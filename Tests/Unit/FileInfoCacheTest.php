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

use MARCMAERDIAN\FalS3Driver\Service\FileInfoCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileInfoCache::class)]
final class FileInfoCacheTest extends TestCase
{
    private FileInfoCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FileInfoCache();
    }

    public function testReturnsNullForUnknownIdentifier(): void
    {
        self::assertNull($this->cache->get('/unknown.txt'));
    }

    public function testStoresAndReturnsInformation(): void
    {
        $this->cache->set('/a.txt', ['size' => 12]);

        self::assertSame(['size' => 12], $this->cache->get('/a.txt'));
    }

    public function testFlushingOneIdentifierLeavesTheRestAlone(): void
    {
        $this->cache->set('/a.txt', ['size' => 1]);
        $this->cache->set('/b.txt', ['size' => 2]);

        $this->cache->flush('/a.txt');

        self::assertNull($this->cache->get('/a.txt'));
        self::assertNotNull($this->cache->get('/b.txt'));
    }

    /**
     * Renaming or deleting a folder invalidates everything inside it. Without
     * this, a move would leave stale entries pointing at objects that are gone.
     */
    public function testFlushingAFolderAlsoFlushesItsContents(): void
    {
        $this->cache->set('/images/', ['size' => 0]);
        $this->cache->set('/images/logo.png', ['size' => 1]);
        $this->cache->set('/images/deep/icon.svg', ['size' => 2]);
        $this->cache->set('/other/keep.txt', ['size' => 3]);

        $this->cache->flush('/images/');

        self::assertNull($this->cache->get('/images/'));
        self::assertNull($this->cache->get('/images/logo.png'));
        self::assertNull($this->cache->get('/images/deep/icon.svg'));
        self::assertNotNull($this->cache->get('/other/keep.txt'));
    }

    /**
     * "/images" and "/images/" name the same folder, so both spellings have to
     * invalidate its contents.
     */
    public function testFlushingWorksWithAndWithoutTrailingSlash(): void
    {
        foreach (['/images', '/images/'] as $spelling) {
            $this->cache->set('/images/logo.png', ['size' => 1]);
            $this->cache->flush($spelling);

            self::assertNull($this->cache->get('/images/logo.png'), 'flushed via ' . $spelling);
        }
    }

    /**
     * A prefix match on the raw string would wrongly hit "/images-old/".
     */
    public function testFlushingAFolderDoesNotHitSimilarlyNamedSiblings(): void
    {
        $this->cache->set('/images/logo.png', ['size' => 1]);
        $this->cache->set('/images-old/logo.png', ['size' => 2]);

        $this->cache->flush('/images/');

        self::assertNull($this->cache->get('/images/logo.png'));
        self::assertNotNull($this->cache->get('/images-old/logo.png'));
    }

    public function testFlushWithoutArgumentDropsEverything(): void
    {
        $this->cache->set('/a.txt', ['size' => 1]);
        $this->cache->set('/b.txt', ['size' => 2]);

        $this->cache->flush();

        self::assertNull($this->cache->get('/a.txt'));
        self::assertNull($this->cache->get('/b.txt'));
    }
}
