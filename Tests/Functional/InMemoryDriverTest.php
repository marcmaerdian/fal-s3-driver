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

namespace MARCMAERDIAN\FalS3Driver\Tests\Functional;

use MARCMAERDIAN\FalS3Driver\Configuration\StorageConfiguration;
use MARCMAERDIAN\FalS3Driver\Driver\S3Driver;
use MARCMAERDIAN\FalS3Driver\Tests\Behaviour\AbstractDriverBehaviourTest;
use MARCMAERDIAN\FalS3Driver\Tests\Fake\InMemoryObjectRepository;
use MARCMAERDIAN\FalS3Driver\Tests\Fake\InMemoryS3Driver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The full driver behaviour, without a bucket and without credentials.
 *
 * A base path is configured on purpose, so every identifier translation is
 * exercised rather than accidentally passing because the prefix is empty.
 */
#[CoversClass(S3Driver::class)]
final class InMemoryDriverTest extends AbstractDriverBehaviourTest
{
    private const BASE_PATH = 'fileadmin';

    private InMemoryObjectRepository $repository;

    /**
     * @var array<string, mixed>
     */
    private array $configuration = [];

    protected function createDriver(): S3Driver
    {
        return $this->buildDriver();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildDriver(array $overrides = []): S3Driver
    {
        $this->configuration = $overrides + [
            'endpoint' => 'https://example.invalid',
            'bucket' => 'test-bucket',
            'accessKeyId' => 'key',
            'secretAccessKey' => 'secret',
            'basePath' => self::BASE_PATH,
        ];

        // A fresh store per build: the double derives Cache-Control from the
        // configuration it was given, exactly as the real repository does, so
        // reusing it would silently keep the previous settings.
        $this->repository = new InMemoryObjectRepository(
            StorageConfiguration::fromArray($this->configuration)
        );

        $driver = new InMemoryS3Driver($this->configuration, $this->repository);
        $driver->processConfiguration();
        $driver->initialize();

        return $driver;
    }

    protected function putRawObject(string $relativePath, string $body): void
    {
        $this->repository->seed(self::BASE_PATH . $this->root . $relativePath, $body);
    }

    protected function headRawObject(string $relativePath): ?array
    {
        return $this->repository->head(self::BASE_PATH . $this->root . $relativePath);
    }

    // =======================================================================
    // Things only controllable with a configuration of our own
    // =======================================================================

    public function testStorageWithoutPublicBaseUrlIsPrivate(): void
    {
        self::assertNull($this->driver->getPublicUrl($this->root . '/readme.txt'));
    }

    /**
     * The public URL has to include the base path: a custom domain points at
     * the bucket root, not at the folder inside it.
     */
    public function testPublicUrlIncludesBasePathAndEncodesSegments(): void
    {
        $driver = $this->buildDriver(['publicBaseUrl' => 'https://cdn.example.com/']);

        self::assertSame(
            'https://cdn.example.com/fileadmin/a%20folder/gr%C3%BCn.png',
            $driver->getPublicUrl('/a folder/grün.png')
        );
    }

    /**
     * Switched on, the hash describes the content rather than the name.
     */
    public function testHashesContentWhenEnabled(): void
    {
        $driver = $this->buildDriver(['useContentHash' => true]);
        $this->putRawObject('/readme.txt', self::README);

        self::assertSame(
            md5(self::README),
            $driver->hash($this->root . '/readme.txt', 'md5')
        );
    }

    public function testCacheControlIsStoredWithTheObject(): void
    {
        $driver = $this->buildDriver(['cacheControlMaxAge' => 604800]);
        $driver->setFileContents($driver->createFile('cached.txt', $this->root . '/'), 'body');

        self::assertSame('max-age=604800', $this->headRawObject('/cached.txt')['CacheControl']);
    }

    public function testNoCacheControlHeaderWhenDurationIsZero(): void
    {
        $this->driver->setFileContents($this->driver->createFile('plain.txt', $this->root . '/'), 'body');

        self::assertSame('', $this->headRawObject('/plain.txt')['CacheControl']);
    }

    // =======================================================================
    // What the metadata cache is actually for
    // =======================================================================

    /**
     * A listing already carries size and timestamp. Asking for file info
     * afterwards must not cost one request per file.
     */
    public function testListingSparesAHeadRequestPerFile(): void
    {
        $this->seedTree();
        $this->repository->resetCallCount();

        foreach ($this->driver->getFilesInFolder($this->root . '/images/') as $identifier) {
            $this->driver->getFileInfoByIdentifier($identifier);
        }

        self::assertSame(0, $this->repository->callCount('head'));
    }

    public function testWithoutAListingTheFirstLookupStillAsks(): void
    {
        $this->seedTree();
        $this->repository->resetCallCount();

        $this->driver->getFileInfoByIdentifier($this->root . '/readme.txt');

        self::assertSame(1, $this->repository->callCount('head'));
    }

    public function testWritingInvalidatesWhatTheListingCached(): void
    {
        $this->seedTree();
        $this->driver->getFilesInFolder($this->root . '/images/');

        $this->driver->setFileContents($this->root . '/images/icon.svg', 'changed');

        self::assertSame(7, $this->driver->getFileInfoByIdentifier($this->root . '/images/icon.svg')['size']);
    }

    public function testDeletingAFolderInvalidatesItsContents(): void
    {
        $this->seedTree();
        $this->driver->getFilesInFolder($this->root . '/images/');

        $this->driver->deleteFolder($this->root . '/images/', true);

        $this->expectException(\TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException::class);
        $this->driver->getFileInfoByIdentifier($this->root . '/images/logo.png');
    }
}
