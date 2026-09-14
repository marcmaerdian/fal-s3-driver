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

namespace MARCMAERDIAN\FalS3Driver\Tests\Integration;

use Aws\S3\S3Client;
use MARCMAERDIAN\FalS3Driver\Driver\S3Driver;
use MARCMAERDIAN\FalS3Driver\Tests\Behaviour\AbstractDriverBehaviourTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The same behaviour, against a real bucket.
 *
 * This is the only thing that can prove the in-memory double still describes
 * reality. It is therefore deliberately hard to run by accident:
 *
 *   1. the group "bucket" is excluded in phpunit.xml.dist, so a plain
 *      `phpunit` never touches it,
 *   2. without credentials every test skips itself.
 *
 * Run it on purpose, against a scratch bucket:
 *
 *   S3_ENV=INT composer test:bucket
 *
 * Everything is written below a randomly named prefix and removed afterwards.
 */
#[CoversClass(S3Driver::class)]
#[Group('bucket')]
final class BucketDriverTest extends AbstractDriverBehaviourTest
{
    /**
     * @var array<string, mixed>
     */
    private array $configuration;

    private S3Client $client;

    /**
     * Object key prefix of this run, base path included.
     */
    private string $prefix;

    protected function setUp(): void
    {
        $this->configuration = $this->readConfiguration();

        parent::setUp();

        $basePath = $this->configuration['basePath'] !== '' ? $this->configuration['basePath'] . '/' : '';
        $this->prefix = $basePath . ltrim($this->root, '/');

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->configuration['region'],
            'endpoint' => $this->configuration['endpoint'],
            'use_path_style_endpoint' => $this->configuration['usePathStyleEndpoint'],
            'credentials' => [
                'key' => $this->configuration['accessKeyId'],
                'secret' => $this->configuration['secretAccessKey'],
            ],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    /**
     * Removes everything below this run's prefix, whatever the test did.
     */
    protected function tearDown(): void
    {
        if (!isset($this->client)) {
            return;
        }

        $objects = [];
        foreach ($this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->configuration['bucket'],
            'Prefix' => $this->prefix,
        ]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[] = ['Key' => (string)$object['Key']];
            }
        }

        if ($objects !== []) {
            $this->client->deleteObjects([
                'Bucket' => $this->configuration['bucket'],
                'Delete' => ['Objects' => $objects],
            ]);
        }
    }

    protected function createDriver(): S3Driver
    {
        $driver = new S3Driver($this->configuration);
        $driver->processConfiguration();
        $driver->initialize();

        return $driver;
    }

    protected function putRawObject(string $relativePath, string $body): void
    {
        $this->client->putObject([
            'Bucket' => $this->configuration['bucket'],
            'Key' => $this->prefix . $relativePath,
            'Body' => $body,
        ]);
    }

    protected function headRawObject(string $relativePath): ?array
    {
        return $this->client->headObject([
            'Bucket' => $this->configuration['bucket'],
            'Key' => $this->prefix . $relativePath,
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfiguration(): array
    {
        $environment = require dirname(__DIR__, 2) . '/bin/environment.php';

        if ($environment['config'] === null) {
            self::markTestSkipped(
                'No bucket configured, missing: ' . implode(', ', $environment['missing'])
                . '. Set them in the shell or in .env.local.'
            );
        }

        return $environment['config'];
    }
}
