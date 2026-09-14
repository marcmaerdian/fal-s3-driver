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

use Aws\S3\S3Client;
use TYPO3\CMS\Core\Resource\Capabilities;
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
        // TODO stage 5: proper sanitisation
        return $fileName;
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

    // ---------------------------------------------------------------------
    // Implemented in later stages.
    // ---------------------------------------------------------------------

    public function getPublicUrl(string $identifier): ?string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function fileExists(string $fileIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFileContents(string $fileIdentifier): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function dumpFileContents(string $identifier): void
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        throw $this->notImplemented(__FUNCTION__);
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
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        throw $this->notImplemented(__FUNCTION__);
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
        throw $this->notImplemented(__FUNCTION__);
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    private function notImplemented(string $method): \RuntimeException
    {
        return new \RuntimeException(
            sprintf('S3Driver::%s() is not implemented yet.', $method),
            1789414273
        );
    }
}
