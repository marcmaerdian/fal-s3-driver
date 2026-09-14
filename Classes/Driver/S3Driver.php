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
    protected ?S3Client $client = null;

    /**
     * Read-only copies already downloaded during this request.
     *
     * @var array<string, string>
     */
    protected array $localCopies = [];

    protected string $endpoint = '';
    protected string $region = '';
    protected string $bucket = '';
    protected string $accessKeyId = '';
    protected string $secretAccessKey = '';
    protected string $publicBaseUrl = '';
    protected string $basePath = '';
    protected bool $usePathStyleEndpoint = true;
    protected bool $compatibilityMode = true;

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
    public function processConfiguration(): void
    {
        $this->endpoint = rtrim(trim((string)($this->configuration['endpoint'] ?? '')), '/');
        $this->region = trim((string)($this->configuration['region'] ?? ''));
        $this->bucket = trim((string)($this->configuration['bucket'] ?? ''));
        $this->accessKeyId = trim((string)($this->configuration['accessKeyId'] ?? ''));
        $this->secretAccessKey = trim((string)($this->configuration['secretAccessKey'] ?? ''));
        $this->publicBaseUrl = rtrim((string)($this->configuration['publicBaseUrl'] ?? ''), '/');
        $this->basePath = trim((string)($this->configuration['basePath'] ?? ''), '/');
        $this->usePathStyleEndpoint = (bool)($this->configuration['usePathStyleEndpoint'] ?? true);
        $this->compatibilityMode = (bool)($this->configuration['compatibilityMode'] ?? true);
    }

    /**
     * Called after processConfiguration().
     */
    public function initialize(): void
    {
        // A storage may be only partially configured while an editor is still
        // filling in the form. Bailing out quietly keeps the file module usable
        // instead of breaking it for every storage.
        if ($this->endpoint === '' || $this->accessKeyId === '' || $this->secretAccessKey === '') {
            return;
        }

        $options = [
            'version' => 'latest',
            // Providers without regions (R2, MinIO) still need a value here,
            // because the SDK uses it when calculating the request signature.
            'region' => $this->region !== '' ? $this->region : 'auto',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->usePathStyleEndpoint,
            'credentials' => [
                'key' => $this->accessKeyId,
                'secret' => $this->secretAccessKey,
            ],
        ];

        if ($this->compatibilityMode) {
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
     * Turns a FAL identifier into an object key:
     * "/images/logo.png" with base path "fileadmin"
     *   becomes "fileadmin/images/logo.png".
     */
    protected function getObjectKey(string $identifier): string
    {
        $key = ltrim($identifier, '/');

        return $this->basePath !== '' ? $this->basePath . '/' . $key : $key;
    }

    /**
     * Inverse of getObjectKey().
     */
    protected function getIdentifierFromObjectKey(string $key): string
    {
        if ($this->basePath !== '' && str_starts_with($key, $this->basePath . '/')) {
            $key = substr($key, strlen($this->basePath) + 1);
        }

        return '/' . ltrim($key, '/');
    }

    /**
     * True if at least one object starts with the given prefix. This is how a
     * store without directories answers the question "does this folder exist".
     */
    protected function prefixHasContent(string $prefix): bool
    {
        $result = $this->getClient()->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 1,
        ]);

        return (int)($result['KeyCount'] ?? 0) > 0;
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
        $prefix = $this->getObjectKey($folderIdentifier);

        $arguments = ['Bucket' => $this->bucket, 'Prefix' => $prefix];
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
                $identifier = $this->getIdentifierFromObjectKey($key);
                $files[$identifier] = $identifier;
            }

            foreach ($page['CommonPrefixes'] ?? [] as $commonPrefix) {
                $identifier = $this->getIdentifierFromObjectKey((string)$commonPrefix['Prefix']);
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
     * Builds the CopySource value for copyObject(). AWS expects it URL
     * encoded, but the slashes separating the path segments must survive.
     */
    protected function getCopySource(string $key): string
    {
        $segments = array_map(rawurlencode(...), explode('/', $key));

        return $this->bucket . '/' . implode('/', $segments);
    }

    protected function detectMimeType(string $fileName, ?string $localFilePath = null): string
    {
        if ($localFilePath !== null && is_readable($localFilePath)) {
            $detected = @mime_content_type($localFilePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== '') {
            $candidates = (new MimeTypeDetector())->getMimeTypesForFileExtension($extension);
            if ($candidates !== []) {
                return (string)reset($candidates);
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Every object key below the given folder, as raw keys.
     *
     * @return array<int, string>
     */
    protected function getObjectKeysInFolder(string $folderIdentifier): array
    {
        $prefix = $this->getObjectKey($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier));

        $keys = [];
        foreach ($this->getClient()->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix]) as $page) {
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

        // DeleteObjects accepts at most 1000 keys per call.
        foreach (array_chunk($keys, 1000) as $chunk) {
            $this->getClient()->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => array_map(static fn(string $key): array => ['Key' => $key], $chunk)],
            ]);
        }
    }

    protected function putObject(string $key, string $body, string $mimeType): void
    {
        $this->getClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $mimeType,
        ]);
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

        $sourcePrefix = $this->getObjectKey($sourceFolderIdentifier);
        $targetPrefix = $this->getObjectKey($targetFolderIdentifier);

        $mapping = [];
        foreach ($this->getObjectKeysInFolder($sourceFolderIdentifier) as $key) {
            $targetKey = $targetPrefix . substr($key, strlen($sourcePrefix));

            $this->getClient()->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $targetKey,
                'CopySource' => $this->getCopySource($key),
            ]);

            // Folder markers are copied but not reported: the mapping tells FAL
            // which file records to rewrite, and a marker has no record.
            if (!str_ends_with($key, '/')) {
                $mapping[$this->getIdentifierFromObjectKey($key)] = $this->getIdentifierFromObjectKey($targetKey);
            }
        }

        return $mapping;
    }

    public function getPublicUrl(string $identifier): ?string
    {
        // A storage without a public base URL is private: FAL then falls back
        // to delivering the file through TYPO3 itself.
        if ($this->publicBaseUrl === '') {
            return null;
        }

        // Encode each segment on its own: rawurlencode('/') would be %2F and
        // destroy the path structure. The core's LocalDriver does the same.
        $parts = explode('/', $this->getObjectKey($identifier));
        $parts = array_map(rawurlencode(...), $parts);

        return $this->publicBaseUrl . '/' . implode('/', $parts);
    }

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFolderName = $this->sanitizeFileName(trim($newFolderName, '/'));
        $folderIdentifier = $this->getFolderInFolder($newFolderName, $parentFolderIdentifier);

        // An object store has no directories, so an empty folder only exists
        // as a zero byte object whose key ends with a slash.
        $this->putObject($this->getObjectKey($folderIdentifier), '', 'application/x-directory');

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
            $this->bucket,
            $this->getObjectKey($fileIdentifier)
        );
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        // The root exists by definition, even in a completely empty bucket.
        if ($folderIdentifier === '/') {
            return true;
        }

        return $this->prefixHasContent($this->getObjectKey($folderIdentifier));
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $prefix = $this->getObjectKey($folderIdentifier);

        $result = $this->getClient()->listObjectsV2([
            'Bucket' => $this->bucket,
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

        $this->getClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($fileIdentifier),
            'SourceFile' => $localFilePath,
            'ContentType' => $this->detectMimeType($newFileName, $localFilePath),
        ]);

        if ($removeOriginal) {
            unlink($localFilePath);
        }

        return $fileIdentifier;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $fileName = $this->sanitizeFileName($fileName);
        $fileIdentifier = $this->getFileInFolder($fileName, $parentFolderIdentifier);

        $this->putObject($this->getObjectKey($fileIdentifier), '', $this->detectMimeType($fileName));

        return $fileIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->getFileInFolder($this->sanitizeFileName($fileName), $targetFolderIdentifier);

        $this->getClient()->copyObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($targetIdentifier),
            'CopySource' => $this->getCopySource($this->getObjectKey($fileIdentifier)),
        ]);

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

        $this->getClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($fileIdentifier),
            'SourceFile' => $localFilePath,
            'ContentType' => $this->detectMimeType($fileIdentifier, $localFilePath),
        ]);

        return true;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $this->getClient()->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($fileIdentifier),
        ]);

        return true;
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        // TODO stage 6: stream the object instead of loading it into memory.
        return hash($hashAlgorithm, $this->getFileContents($fileIdentifier));
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
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($this->canonicalizeAndCheckFileIdentifier($fileIdentifier)),
        ]);

        return (string)$result['Body'];
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $this->putObject(
            $this->getObjectKey($fileIdentifier),
            $contents,
            $this->detectMimeType($fileIdentifier)
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
                'Bucket' => $this->bucket,
                'Key' => $this->getObjectKey($fileIdentifier),
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

        if (!$writable) {
            $this->localCopies[$fileIdentifier] = $temporaryPath;
        }

        return $temporaryPath;
    }

    public function dumpFileContents(string $identifier): void
    {
        $result = $this->getClient()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getObjectKey($this->canonicalizeAndCheckFileIdentifier($identifier)),
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

        try {
            $head = $this->getClient()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getObjectKey($fileIdentifier),
            ]);
        } catch (AwsException $exception) {
            throw new FileDoesNotExistException(
                'File ' . $fileIdentifier . ' does not exist.',
                1789344003,
                $exception
            );
        }

        $lastModified = $head['LastModified'] ?? null;
        $mtime = $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : 0;

        // An object store knows neither access nor inode change time, so the
        // modification time stands in for all three.
        $information = [
            'size' => (int)($head['ContentLength'] ?? 0),
            'atime' => $mtime,
            'mtime' => $mtime,
            'ctime' => $mtime,
            'mimetype' => (string)($head['ContentType'] ?? 'application/octet-stream'),
            'name' => basename($fileIdentifier),
            'extension' => strtolower(pathinfo($fileIdentifier, PATHINFO_EXTENSION)),
            'identifier' => $fileIdentifier,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'storage' => $this->storageUid,
            'folder_hash' => $this->hashIdentifier(
                $this->canonicalizeAndCheckFolderIdentifier(dirname($fileIdentifier))
            ),
        ];

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
