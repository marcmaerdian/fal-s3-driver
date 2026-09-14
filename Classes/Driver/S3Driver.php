<?php

declare(strict_types=1);

/***
*
* This file is part of an extension for TYPO3 CMS.
*
* For the full copyright and license information, please read the
* LICENSE.txt file that was distributed with this source code.
*
* (c) by 2026 Marc Märdian Softwaredevelopment
* kontakt@marcmaerdian.de
*
***/

namespace Marcmaerdian\FalS3Driver\Driver;

use Aws\Exception\AwsException;
use Marcmaerdian\FalS3Driver\Configuration\StorageConfiguration;
use Marcmaerdian\FalS3Driver\Service\FileInfoCache;
use Marcmaerdian\FalS3Driver\Service\MimeTypeGuesser;
use Marcmaerdian\FalS3Driver\Service\ObjectKeyMapper;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;

/**
 * FAL driver for S3-compatible object storage.
 *
 * Works with any provider speaking the S3 API: Cloudflare R2, Hetzner
 * Object Storage, MinIO, Backblaze B2, Wasabi, AWS S3 itself.
 */
class S3Driver extends AbstractHierarchicalFilesystemDriver
{
    /**
     * Files above this size are uploaded in parts. A single PutObject is
     * capped at 5 GB by the S3 API, and a long running upload of a large file
     * is far more likely to survive when it can retry individual parts.
     */
    protected int $multipartThreshold = 64 * 1024 * 1024;

    protected ?S3Client $client = null;

    /**
     * Read-only copies already downloaded during this request.
     *
     * @var array<string, string>
     */
    protected array $localCopies = [];

    /**
     * Every temporary file created by this driver, removed on destruction.
     *
     * @var array<string, string>
     */
    protected array $temporaryPaths = [];


    protected StorageConfiguration $config;
    protected ObjectKeyMapper $keys;
    protected MimeTypeGuesser $mimeTypes;
    protected FileInfoCache $fileInfo;

    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);

        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_PUBLIC
            | Capabilities::CAPABILITY_WRITABLE
            | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS
        );
    }

    /**
     * Called right after the driver is built, with the FlexForm values
     * already present in $this->configuration.
     */
    public function __destruct()
    {
        foreach ($this->temporaryPaths as $temporaryPath) {
            @unlink($temporaryPath);
        }
    }

    public function processConfiguration(): void
    {
        $this->config = StorageConfiguration::fromArray($this->configuration);
        $this->keys = new ObjectKeyMapper($this->config->bucket, $this->config->basePath);
        $this->mimeTypes = new MimeTypeGuesser();
        $this->fileInfo = new FileInfoCache();
    }

    /**
     * Called after processConfiguration().
     */
    public function initialize(): void
    {
        // A storage may be only partially configured while an editor is still
        // filling in the form. Bailing out quietly keeps the file module usable
        // instead of breaking it for every storage.
        if (!$this->config->isComplete()) {
            return;
        }

        $options = [
            'version' => 'latest',
            // Providers without regions (R2, MinIO) still need a value here,
            // because the SDK uses it when calculating the request signature.
            'region' => $this->config->region,
            'endpoint' => $this->config->endpoint,
            'use_path_style_endpoint' => $this->config->usePathStyleEndpoint,
            'credentials' => [
                'key' => $this->config->accessKeyId,
                'secret' => $this->config->secretAccessKey,
            ],
        ];

        if ($this->config->compatibilityMode) {
            // Most non-AWS providers do not implement the integrity checksums
            // the SDK sends by default and answer with 501 Not Implemented.
            $options['request_checksum_calculation'] = 'when_required';
            $options['response_checksum_validation'] = 'when_required';
        }

        $this->client = new S3Client($options);
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);
        return $this->capabilities;
    }

    public function getRootLevelFolder(): string
    {
        return '/';
    }

    public function getDefaultFolder(): string
    {
        return '/';
    }

    public function sanitizeFileName(string $fileName): string
    {
        $fileName = \Normalizer::normalize($fileName) ?: $fileName;

        // Object keys are UTF-8 throughout, so unlike the LocalDriver there is
        // no need for a non-UTF-8 filesystem branch.
        $cleanFileName = (string)preg_replace(
            '/[' . LocalDriver::UNSAFE_FILENAME_CHARACTER_EXPRESSION . ']/u',
            '_',
            trim($fileName)
        );

        // A trailing dot would make the key indistinguishable from a folder
        // marker in some clients.
        $cleanFileName = rtrim($cleanFileName, '.');

        if ($cleanFileName === '') {
            throw new InvalidFileNameException('File name ' . $fileName . ' is invalid.', 1789344005);
        }

        return $cleanFileName;
    }

    public function getPermissions(string $identifier): array
    {
        // R2 has no per-object permissions; access is controlled by the token.
        return ['r' => true, 'w' => true];
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);

        if ($folderIdentifier === $identifier) {
            return true;
        }

        return str_starts_with($identifier, $folderIdentifier);
    }

    protected function getClient(): S3Client
    {
        if ($this->client === null) {
            throw new \RuntimeException(
                'The storage is not configured: endpoint or credentials are missing.',
                1789416429
            );
        }

        return $this->client;
    }



    /**
     * True if at least one object starts with the given prefix. This is how a
     * store without directories answers the question "does this folder exist".
     */
    protected function prefixHasContent(string $prefix): bool
    {
        $result = $this->getClient()->listObjectsV2([
            'Bucket' => $this->config->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 1,
        ]);

        return (int)($result['KeyCount'] ?? 0) > 0;
    }

    /**
     * Builds the file information array for one object.
     *
     * $mimeType is optional because ListObjectsV2 does not report content
     * types. Deriving it from the extension keeps a folder listing at a single
     * request instead of one HeadObject per file.
     *
     * @return array<string, mixed>
     */
    protected function buildFileInfo(string $fileIdentifier, int $size, int $mtime, ?string $mimeType = null): array
    {
        return [
            'size' => $size,
            // An object store knows neither access nor inode change time, so
            // the modification time stands in for all three.
            'atime' => $mtime,
            'mtime' => $mtime,
            'ctime' => $mtime,
            'mimetype' => $mimeType ?? $this->mimeTypes->guess($fileIdentifier),
            'name' => basename($fileIdentifier),
            'extension' => strtolower(pathinfo($fileIdentifier, PATHINFO_EXTENSION)),
            'identifier' => $fileIdentifier,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'storage' => $this->storageUid,
            'folder_hash' => $this->hashIdentifier(
                $this->canonicalizeAndCheckFolderIdentifier(dirname($fileIdentifier))
            ),
        ];
    }


    /**
     * Lists one folder level. A flat object store has no directories, so the
     * "folders" are derived from the common prefixes the API reports when a
     * delimiter is given.
     *
     * @return array{files: array<string, string>, folders: array<string, string>}
     */
    protected function listFolder(string $folderIdentifier, bool $recursive = false): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $prefix = $this->keys->toObjectKey($folderIdentifier);

        $arguments = ['Bucket' => $this->config->bucket, 'Prefix' => $prefix];
        if (!$recursive) {
            // Without a delimiter the API returns every object below the
            // prefix, no matter how deep. With it, anything deeper is folded
            // into CommonPrefixes instead.
            $arguments['Delimiter'] = '/';
        }

        $files = [];
        $folders = [];

        // The paginator hides the 1000 keys per response limit.
        foreach ($this->getClient()->getPaginator('ListObjectsV2', $arguments) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = (string)$object['Key'];
                // The zero byte object representing the folder itself is not
                // one of its children.
                if ($key === $prefix || str_ends_with($key, '/')) {
                    continue;
                }

                // Belt and braces: should a provider ignore the delimiter, do
                // not let objects from deeper levels leak into a flat listing.
                if (!$recursive && substr_count($key, '/') !== substr_count($prefix, '/')) {
                    continue;
                }
                $identifier = $this->keys->toIdentifier($key);
                $files[$identifier] = $identifier;

                // The listing already carries size and timestamp, so remember
                // them instead of asking for each file separately later on.
                $lastModified = $object['LastModified'] ?? null;
                $this->fileInfo->set($identifier, $this->buildFileInfo(
                    $identifier,
                    (int)($object['Size'] ?? 0),
                    $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : 0
                );
            }

            foreach ($page['CommonPrefixes'] ?? [] as $commonPrefix) {
                $identifier = $this->keys->toIdentifier((string)$commonPrefix['Prefix']);
                $folders[$identifier] = $identifier;
            }
        }

        if ($recursive) {
            // Recursive listings have no CommonPrefixes, so the folders have
            // to be reconstructed from the keys themselves.
            foreach (array_keys($files) as $identifier) {
                $parent = $this->canonicalizeAndCheckFolderIdentifier(dirname($identifier));
                while ($parent !== '/' && $parent !== $folderIdentifier && !isset($folders[$parent])) {
                    $folders[$parent] = $parent;
                    $parent = $this->canonicalizeAndCheckFolderIdentifier(dirname(rtrim($parent, '/')));
                }
            }
        }

        ksort($files);
        ksort($folders);

        return ['files' => $files, 'folders' => $folders];
    }

    /**
     * Copy of the LocalDriver behaviour: a filter returning -1 excludes the
     * item, FALSE means the filter itself is broken.
     *
     * @param array<callable> $filterMethods
     */
    protected function applyFilterMethodsToDirectoryItem(
        array $filterMethods,
        string $itemName,
        string $itemIdentifier,
        string $parentIdentifier
    ): bool {
        foreach ($filterMethods as $filter) {
            if (!is_callable($filter)) {
                continue;
            }
            $result = $filter($itemName, $itemIdentifier, $parentIdentifier, [], $this);
            if ($result === -1) {
                return false;
            }
            if ($result === false) {
                throw new \RuntimeException('Could not apply file/folder name filter.', 1789344002);
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $items
     * @param array<callable> $filterMethods
     * @return array<string, string>
     */
    protected function filterAndSlice(
        array $items,
        string $parentIdentifier,
        array $filterMethods,
        int $start,
        int $numberOfItems,
        bool $sortRev
    ): array {
        $filtered = [];
        foreach ($items as $identifier) {
            $name = basename(rtrim($identifier, '/'));
            if ($this->applyFilterMethodsToDirectoryItem($filterMethods, $name, $identifier, $parentIdentifier)) {
                $filtered[$identifier] = $identifier;
            }
        }

        if ($sortRev) {
            $filtered = array_reverse($filtered, true);
        }

        if ($start > 0 || $numberOfItems > 0) {
            $filtered = array_slice($filtered, $start, $numberOfItems > 0 ? $numberOfItems : null, true);
        }

        return $filtered;
    }



    /**
     * Every object key below the given folder, as raw keys.
     *
     * @return array<int, string>
     */
    protected function getObjectKeysInFolder(string $folderIdentifier): array
    {
        $prefix = $this->keys->toObjectKey($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier));

        $keys = [];
        foreach ($this->getClient()->getPaginator('ListObjectsV2', ['Bucket' => $this->config->bucket, 'Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = (string)$object['Key'];
            }
        }

        return $keys;
    }

    /**
     * @param array<int, string> $keys
     */
    protected function deleteObjectKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        foreach ($keys as $key) {
            $this->fileInfo->flush($this->keys->toIdentifier($key));
        }

        // DeleteObjects accepts at most 1000 keys per call.
        foreach (array_chunk($keys, 1000) as $chunk) {
            $this->getClient()->deleteObjects([
                'Bucket' => $this->config->bucket,
                'Delete' => ['Objects' => array_map(static fn(string $key): array => ['Key' => $key], $chunk)],
            ]);
        }
    }

    /**
     * Cache-Control for uploaded objects. Without it the CDN in front of the
     * bucket decides on its own how long it keeps a file.
     *
     * @return array<string, string>
     */
    protected function getUploadOptions(): array
    {
        return $this->config->cacheControlMaxAge > 0
            ? ['CacheControl' => 'max-age=' . $this->config->cacheControlMaxAge]
            : [];
    }

    /**
     * Uploads a local file, switching to a multipart upload for large ones.
     */
    protected function uploadFile(string $localFilePath, string $key, string $mimeType): void
    {
        $parameters = ['ContentType' => $mimeType] + $this->getUploadOptions();
        $size = (int)filesize($localFilePath);

        if ($size > $this->multipartThreshold) {
            $uploader = new MultipartUploader($this->getClient(), $localFilePath, [
                'bucket' => $this->config->bucket,
                'key' => $key,
                'params' => $parameters,
            ]);
            $uploader->upload();
        } else {
            // A multipart upload cannot handle zero byte files at all, and for
            // small ones the extra round trips are not worth it.
            $this->getClient()->putObject([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'SourceFile' => $localFilePath,
            ] + $parameters);
        }

        $this->fileInfo->flush($this->keys->toIdentifier($key));
    }

    protected function putObject(string $key, string $body, string $mimeType): void
    {
        $this->getClient()->putObject([
            'Bucket' => $this->config->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $mimeType,
        ] + $this->getUploadOptions());

        $this->fileInfo->flush($this->keys->toIdentifier($key));
    }

    /**
     * Copies everything below a folder to a new prefix and reports the mapping
     * of old to new identifiers, which is what FAL needs to update its records.
     *
     * @return array<string, string>
     */
    protected function copyFolderContents(string $sourceFolderIdentifier, string $targetFolderIdentifier): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);

        $sourcePrefix = $this->keys->toObjectKey($sourceFolderIdentifier);
        $targetPrefix = $this->keys->toObjectKey($targetFolderIdentifier);

        $mapping = [];
        foreach ($this->getObjectKeysInFolder($sourceFolderIdentifier) as $key) {
            $targetKey = $targetPrefix . substr($key, strlen($sourcePrefix));

            $this->getClient()->copyObject([
                'Bucket' => $this->config->bucket,
                'Key' => $targetKey,
                'CopySource' => $this->keys->toCopySource($key),
            ] + $this->getUploadOptions());

            $this->fileInfo->flush($this->keys->toIdentifier($targetKey));

            // Folder markers are copied but not reported: the mapping tells FAL
            // which file records to rewrite, and a marker has no record.
            if (!str_ends_with($key, '/')) {
                $mapping[$this->keys->toIdentifier($key)] = $this->keys->toIdentifier($targetKey);
            }
        }

        return $mapping;
    }

    public function getPublicUrl(string $identifier): ?string
    {
        // A storage without a public base URL is private: FAL then falls back
        // to delivering the file through TYPO3 itself.
        if ($this->config->publicBaseUrl === '') {
            return null;
        }

        return $this->config->publicBaseUrl . '/'
            . $this->keys->encodeSegments($this->keys->toObjectKey($identifier));
    }

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFolderName = trim($newFolderName, '/');

        // sanitizeFileName() turns a slash into an underscore, so a recursive
        // name has to be split first and every segment cleaned on its own.
        $segments = $recursive ? explode('/', $newFolderName) : [$newFolderName];
        $segments = array_map($this->sanitizeFileName(...), array_filter($segments, static fn(string $part): bool => $part !== ''));

        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $parentFolderIdentifier . implode('/', $segments)
        );

        // An object store has no directories, so an empty folder only exists
        // as a zero byte object whose key ends with a slash.
        $this->putObject($this->keys->toObjectKey($folderIdentifier), '', 'application/x-directory');

        return $folderIdentifier;
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(dirname(rtrim($folderIdentifier, '/')));

        // A rename is a move inside the same parent folder.
        return $this->moveFolderWithinStorage($folderIdentifier, $parentFolderIdentifier, $newName);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        if (!$deleteRecursively && !$this->isFolderEmpty($folderIdentifier)) {
            throw new \RuntimeException(
                'Folder "' . $folderIdentifier . '" is not empty.',
                1789344006
            );
        }

        $this->deleteObjectKeys($this->getObjectKeysInFolder($folderIdentifier));

        return true;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        // A trailing slash marks a folder, and a folder is never a file. This
        // has to be checked on the raw value: canonicalisation strips it.
        if ($fileIdentifier === '' || str_ends_with($fileIdentifier, '/')) {
            return false;
        }

        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return $this->getClient()->doesObjectExistV2(
            $this->config->bucket,
            $this->keys->toObjectKey($fileIdentifier)
        );
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        // The root exists by definition, even in a completely empty bucket.
        if ($folderIdentifier === '/') {
            return true;
        }

        return $this->prefixHasContent($this->keys->toObjectKey($folderIdentifier));
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $prefix = $this->keys->toObjectKey($folderIdentifier);

        $result = $this->getClient()->listObjectsV2([
            'Bucket' => $this->config->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 2,
        ]);

        foreach ($result['Contents'] ?? [] as $object) {
            // The folder marker itself is not content.
            if ($object['Key'] !== $prefix) {
                return false;
            }
        }

        return true;
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        if (!is_readable($localFilePath)) {
            throw new \InvalidArgumentException('File ' . $localFilePath . ' is not readable.', 1789344007);
        }

        $newFileName = $this->sanitizeFileName($newFileName !== '' ? $newFileName : basename($localFilePath));
        $fileIdentifier = $this->getFileInFolder($newFileName, $targetFolderIdentifier);

        $this->uploadFile(
            $localFilePath,
            $this->keys->toObjectKey($fileIdentifier),
            $this->mimeTypes->guess($newFileName, $localFilePath)
        );

        if ($removeOriginal) {
            unlink($localFilePath);
        }

        return $fileIdentifier;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $fileName = $this->sanitizeFileName($fileName);
        $fileIdentifier = $this->getFileInFolder($fileName, $parentFolderIdentifier);

        $this->putObject($this->keys->toObjectKey($fileIdentifier), '', $this->mimeTypes->guess($fileName));

        return $fileIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->getFileInFolder($this->sanitizeFileName($fileName), $targetFolderIdentifier);

        $this->getClient()->copyObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->keys->toObjectKey($targetIdentifier),
            'CopySource' => $this->keys->toCopySource($this->keys->toObjectKey($fileIdentifier)),
            'MetadataDirective' => 'REPLACE',
            'ContentType' => $this->mimeTypes->guess($targetIdentifier),
        ] + $this->getUploadOptions());

        $this->fileInfo->flush($targetIdentifier);

        return $targetIdentifier;
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(dirname($fileIdentifier));

        return $this->moveFileWithinStorage($fileIdentifier, $parentFolderIdentifier, $newName);
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        if (!is_readable($localFilePath)) {
            throw new \InvalidArgumentException('File ' . $localFilePath . ' is not readable.', 1789344008);
        }

        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $this->uploadFile(
            $localFilePath,
            $this->keys->toObjectKey($fileIdentifier),
            $this->mimeTypes->guess($fileIdentifier, $localFilePath)
        );

        return true;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $this->getClient()->deleteObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->keys->toObjectKey($fileIdentifier),
        ]);

        $this->fileInfo->flush($fileIdentifier);

        return true;
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        // TYPO3 calls hash() while indexing every single file. Downloading each
        // of them would make the storage unusable, so the identifier hash is
        // the default and content hashing has to be switched on deliberately.
        if (!$this->config->useContentHash) {
            return $this->hashIdentifier($fileIdentifier);
        }

        $body = $this->getClient()->getObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->keys->toObjectKey($fileIdentifier),
        ])['Body'];

        if (!$body instanceof StreamInterface) {
            return hash($hashAlgorithm, (string)$body);
        }

        // Hash in chunks: a large video must not have to fit into memory.
        $context = hash_init($hashAlgorithm);
        while (!$body->eof()) {
            hash_update($context, $body->read(32768));
        }

        return hash_final($context);
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        // S3 has no rename: a move is a copy followed by a delete.
        $targetIdentifier = $this->copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName);
        $this->deleteFile($fileIdentifier);

        return $targetIdentifier;
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $destination = $this->getFolderInFolder($this->sanitizeFileName($newFolderName), $targetFolderIdentifier);

        $mapping = $this->copyFolderContents($sourceFolderIdentifier, $destination);
        $this->deleteObjectKeys($this->getObjectKeysInFolder($sourceFolderIdentifier));

        return $mapping;
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        $destination = $this->getFolderInFolder($this->sanitizeFileName($newFolderName), $targetFolderIdentifier);
        $this->copyFolderContents($sourceFolderIdentifier, $destination);

        return true;
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $result = $this->getClient()->getObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->keys->toObjectKey($this->canonicalizeAndCheckFileIdentifier($fileIdentifier)),
        ]);

        return (string)$result['Body'];
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $this->putObject(
            $this->keys->toObjectKey($fileIdentifier),
            $contents,
            $this->mimeTypes->guess($fileIdentifier)
        );

        return strlen($contents);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        return $this->fileExists($this->getFileInFolder($fileName, $folderIdentifier));
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        return $this->folderExists($this->getFolderInFolder($folderName, $folderIdentifier));
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        // Read-only copies may be reused within the request. A writable copy
        // must always be fresh, because the caller is allowed to modify it.
        if (!$writable && isset($this->localCopies[$fileIdentifier]) && is_readable($this->localCopies[$fileIdentifier])) {
            return $this->localCopies[$fileIdentifier];
        }

        $extension = pathinfo($fileIdentifier, PATHINFO_EXTENSION);
        $temporaryPath = GeneralUtility::tempnam('fal-s3-', $extension !== '' ? '.' . $extension : '');

        try {
            $this->getClient()->getObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->keys->toObjectKey($fileIdentifier),
                'SaveAs' => $temporaryPath,
            ]);
        } catch (AwsException $exception) {
            @unlink($temporaryPath);

            throw new FileDoesNotExistException(
                'File ' . $fileIdentifier . ' could not be downloaded for processing.',
                1789344009,
                $exception
            );
        }

        // A failed SaveAs can leave the error body behind instead of the file.
        if (!is_file($temporaryPath)) {
            throw new FileDoesNotExistException(
                'File ' . $fileIdentifier . ' could not be copied to a temporary path.',
                1789344010
            );
        }

        $this->temporaryPaths[$temporaryPath] = $temporaryPath;

        if (!$writable) {
            $this->localCopies[$fileIdentifier] = $temporaryPath;
        }

        return $temporaryPath;
    }

    public function dumpFileContents(string $identifier): void
    {
        $result = $this->getClient()->getObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->keys->toObjectKey($this->canonicalizeAndCheckFileIdentifier($identifier)),
        ]);

        $body = $result['Body'] ?? null;

        // Stream in chunks rather than materialising the whole object: a video
        // would otherwise have to fit into PHP's memory limit.
        if ($body instanceof StreamInterface) {
            while (!$body->eof()) {
                echo $body->read(8192);
            }

            return;
        }

        echo (string)$body;
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $information = $this->metaInfoCache[$fileIdentifier] ?? null;

        if ($information === null) {
            try {
                $head = $this->getClient()->headObject([
                    'Bucket' => $this->config->bucket,
                    'Key' => $this->keys->toObjectKey($fileIdentifier),
                ]);
            } catch (AwsException $exception) {
                throw new FileDoesNotExistException(
                    'File ' . $fileIdentifier . ' does not exist.',
                    1789344003,
                    $exception
                );
            }

            $lastModified = $head['LastModified'] ?? null;
            $information = $this->buildFileInfo(
                $fileIdentifier,
                (int)($head['ContentLength'] ?? 0),
                $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : 0,
                (string)($head['ContentType'] ?? 'application/octet-stream')
            );

            $this->fileInfo->set($fileIdentifier, $information;
        }

        if ($propertiesToExtract === []) {
            return $information;
        }

        return array_intersect_key($information, array_flip($propertiesToExtract));
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        if (!$this->folderExists($folderIdentifier)) {
            throw new FolderDoesNotExistException(
                'Folder "' . $folderIdentifier . '" does not exist.',
                1789344004
            );
        }

        // Folders are virtual here, so they carry no timestamps of their own.
        return [
            'identifier' => $folderIdentifier,
            'name' => basename(rtrim($folderIdentifier, '/')),
            'mtime' => 0,
            'ctime' => 0,
            'storage' => $this->storageUid,
        ];
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFileIdentifier($folderIdentifier . '/' . $fileName);
    }

    public function getFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        return $this->filterAndSlice(
            $this->listFolder($folderIdentifier, $recursive)['files'],
            $folderIdentifier,
            $filenameFilterCallbacks,
            $start,
            $numberOfItems,
            $sortRev
        );
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier . '/' . $folderName);
    }

    public function getFoldersInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $folderNameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        return $this->filterAndSlice(
            $this->listFolder($folderIdentifier, $recursive)['folders'],
            $folderIdentifier,
            $folderNameFilterCallbacks,
            $start,
            $numberOfItems,
            $sortRev
        );
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        return count($this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks));
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks));
    }
}
