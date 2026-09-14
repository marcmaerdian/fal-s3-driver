<?php

declare(strict_types=1);

namespace Marcmaerdian\FalR2Driver\Driver;

use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;

/**
 * FAL driver for Cloudflare R2 object storage.
 *
 * R2 speaks the S3 API, so this driver talks S3 against an R2 endpoint.
 */
class R2Driver extends AbstractHierarchicalFilesystemDriver
{
    protected string $accountId = '';
    protected string $bucket = '';
    protected string $accessKeyId = '';
    protected string $secretAccessKey = '';
    protected string $publicBaseUrl = '';
    protected string $basePath = '';

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
        $this->accountId = trim((string)($this->configuration['accountId'] ?? ''));
        $this->bucket = trim((string)($this->configuration['bucket'] ?? ''));
        $this->accessKeyId = trim((string)($this->configuration['accessKeyId'] ?? ''));
        $this->secretAccessKey = trim((string)($this->configuration['secretAccessKey'] ?? ''));
        $this->publicBaseUrl = rtrim((string)($this->configuration['publicBaseUrl'] ?? ''), '/');
        $this->basePath = trim((string)($this->configuration['basePath'] ?? ''), '/');
    }

    /**
     * Called after processConfiguration().
     */
    public function initialize(): void
    {
        // TODO stage 3: create the S3 client against the R2 endpoint
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
            sprintf('R2Driver::%s() is not implemented yet.', $method),
            1789414273
        );
    }
}