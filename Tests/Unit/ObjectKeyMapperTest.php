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

use MARCMAERDIAN\FalS3Driver\Service\ObjectKeyMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectKeyMapper::class)]
final class ObjectKeyMapperTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function keysWithBasePath(): array
    {
        return [
            'file' => ['fileadmin', '/images/logo.png', 'fileadmin/images/logo.png'],
            'folder keeps trailing slash' => ['fileadmin', '/images/', 'fileadmin/images/'],
            'root folder' => ['fileadmin', '/', 'fileadmin/'],
            'nested file' => ['fileadmin', '/a/b/c.txt', 'fileadmin/a/b/c.txt'],
            'no base path' => ['', '/images/logo.png', 'images/logo.png'],
            'no base path, folder' => ['', '/images/', 'images/'],
            'no base path, root' => ['', '/', ''],
        ];
    }

    #[DataProvider('keysWithBasePath')]
    public function testTranslatesIdentifierToObjectKey(string $basePath, string $identifier, string $expected): void
    {
        self::assertSame($expected, (new ObjectKeyMapper($basePath))->toObjectKey($identifier));
    }

    #[DataProvider('keysWithBasePath')]
    public function testTranslationIsReversible(string $basePath, string $identifier, string $key): void
    {
        self::assertSame($identifier, (new ObjectKeyMapper($basePath))->toIdentifier($key));
    }

    /**
     * The trailing slash is the only thing telling a folder from a file, so it
     * must never be trimmed away.
     */
    public function testTrailingSlashSurvivesTranslation(): void
    {
        $mapper = new ObjectKeyMapper('fileadmin');

        self::assertStringEndsWith('/', $mapper->toObjectKey('/images/'));
        self::assertStringEndsNotWith('/', $mapper->toObjectKey('/images/logo.png'));
    }

    public function testKeyOutsideBasePathIsLeftAlone(): void
    {
        $mapper = new ObjectKeyMapper('fileadmin');

        // Not below the base path, so nothing may be stripped off the front.
        self::assertSame('/other/logo.png', $mapper->toIdentifier('other/logo.png'));
    }

    public function testBasePathIsOnlyStrippedAsAWholeSegment(): void
    {
        $mapper = new ObjectKeyMapper('file');

        // "fileadmin" starts with "file" but is a different folder.
        self::assertSame('/fileadmin/logo.png', $mapper->toIdentifier('fileadmin/logo.png'));
    }


    public function testEncodingNeverTurnsSlashesIntoPercent2F(): void
    {
        $encoded = (new ObjectKeyMapper(''))->encodeSegments('a/b/c d.png');

        self::assertSame('a/b/c%20d.png', $encoded);
        self::assertStringNotContainsString('%2F', $encoded);
    }
}
