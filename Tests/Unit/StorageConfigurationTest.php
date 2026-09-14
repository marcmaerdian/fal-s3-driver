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

namespace MARCMAERDIAN\FalS3Driver\Tests\Unit;

use MARCMAERDIAN\FalS3Driver\Configuration\StorageConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageConfiguration::class)]
final class StorageConfigurationTest extends TestCase
{
    public function testReadsEveryFlexFormField(): void
    {
        $config = StorageConfiguration::fromArray([
            'endpoint' => 'https://example.r2.cloudflarestorage.com/',
            'region' => 'fsn1',
            'bucket' => 'my-bucket',
            'accessKeyId' => 'key',
            'secretAccessKey' => 'secret',
            'publicBaseUrl' => 'https://cdn.example.com/',
            'basePath' => '/fileadmin/',
            'usePathStyleEndpoint' => '0',
            'compatibilityMode' => '0',
            'useContentHash' => '1',
            'cacheControlMaxAge' => '604800',
        ]);

        self::assertSame('https://example.r2.cloudflarestorage.com', $config->endpoint, 'trailing slash removed');
        self::assertSame('fsn1', $config->region);
        self::assertSame('my-bucket', $config->bucket);
        self::assertSame('https://cdn.example.com', $config->publicBaseUrl, 'trailing slash removed');
        self::assertSame('fileadmin', $config->basePath, 'surrounding slashes removed');
        self::assertFalse($config->usePathStyleEndpoint);
        self::assertFalse($config->compatibilityMode);
        self::assertTrue($config->useContentHash);
        self::assertSame(604800, $config->cacheControlMaxAge);
    }

    /**
     * The defaults are what an editor gets when a storage is created, so they
     * have to be the values that work with the most providers.
     */
    public function testDefaultsFavourNonAwsProviders(): void
    {
        $config = StorageConfiguration::fromArray([]);

        self::assertSame('auto', $config->region, 'providers without regions still need a value for signing');
        self::assertTrue($config->usePathStyleEndpoint, 'R2 and MinIO only support path style');
        self::assertTrue($config->compatibilityMode, 'non-AWS providers reject the default checksums');
        self::assertFalse($config->useContentHash, 'hashing contents would download every indexed file');
        self::assertSame(0, $config->cacheControlMaxAge);
    }

    public function testEmptyRegionFallsBackToAuto(): void
    {
        self::assertSame('auto', StorageConfiguration::fromArray(['region' => '   '])->region);
    }

    public function testNegativeCacheDurationIsClampedToZero(): void
    {
        self::assertSame(0, StorageConfiguration::fromArray(['cacheControlMaxAge' => '-5'])->cacheControlMaxAge);
    }

    public function testIsCompleteRequiresEndpointAndCredentials(): void
    {
        $complete = ['endpoint' => 'https://e', 'accessKeyId' => 'k', 'secretAccessKey' => 's'];

        self::assertTrue(StorageConfiguration::fromArray($complete)->isComplete());

        foreach (array_keys($complete) as $missing) {
            $partial = $complete;
            unset($partial[$missing]);

            self::assertFalse(
                StorageConfiguration::fromArray($partial)->isComplete(),
                sprintf('a storage without "%s" cannot connect', $missing)
            );
        }
    }

    public function testBucketIsNotRequiredForCompleteness(): void
    {
        // The bucket is validated by the API on first use; a missing one must
        // not stop the driver from being built, or the file module breaks for
        // every storage while an editor is still filling in the form.
        self::assertTrue(StorageConfiguration::fromArray([
            'endpoint' => 'https://e',
            'accessKeyId' => 'k',
            'secretAccessKey' => 's',
        ])->isComplete());
    }
}
