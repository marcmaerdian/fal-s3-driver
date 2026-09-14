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

namespace MARCMAERDIAN\FalS3Driver\Tests\Behaviour;

use MARCMAERDIAN\FalS3Driver\Driver\S3Driver;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;

/**
 * What the driver has to do, independent of where the objects actually live.
 *
 * This class holds no setup of its own: subclasses decide whether the driver
 * talks to an in-memory double or to a real bucket. Both run the identical
 * assertions, which is the only way to be sure the double still describes
 * reality.
 */
abstract class AbstractDriverBehaviourTest extends TestCase
{
    protected const README = 'hello from the driver test';

    protected S3Driver $driver;

    /**
     * FAL identifier the test tree lives under, without a trailing slash.
     */
    protected string $root;

    abstract protected function createDriver(): S3Driver;

    /**
     * Writes an object without going through the driver, to set up a scenario.
     *
     * @param string $relativePath Below $root, starting with a slash
     */
    abstract protected function putRawObject(string $relativePath, string $body): void;

    /**
     * Reads object metadata without going through the driver.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function headRawObject(string $relativePath): ?array;

    protected function setUp(): void
    {
        $this->root = '/testrun-' . bin2hex(random_bytes(4));
        $this->driver = $this->createDriver();
    }

    /**
     *   readme.txt
     *   images/logo.png
     *   images/icon.svg
     *   docs/deep/manual.txt
     */
    protected function seedTree(): void
    {
        $this->putRawObject('/readme.txt', self::README);
        $this->putRawObject('/images/logo.png', 'not really a png');
        $this->putRawObject('/images/icon.svg', '<svg></svg>');
        $this->putRawObject('/docs/deep/manual.txt', 'deeply nested');
    }

    // =======================================================================
    // Existence
    // =======================================================================

    /**
     * An empty bucket has no object for "/", yet the root folder has to exist
     * or TYPO3 marks the whole storage as broken.
     */
    public function testRootFolderExistsEvenWhenEmpty(): void
    {
        self::assertTrue($this->driver->folderExists('/'));
    }

    public function testFolderExistsWhenAnythingIsBelowIt(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->folderExists($this->root . '/'));
        self::assertTrue($this->driver->folderExists($this->root . '/images/'));
        self::assertTrue($this->driver->folderExists($this->root . '/docs/deep/'));
        self::assertFalse($this->driver->folderExists($this->root . '/nope/'));
    }

    public function testFileExists(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->fileExists($this->root . '/readme.txt'));
        self::assertTrue($this->driver->fileExists($this->root . '/images/logo.png'));
        self::assertFalse($this->driver->fileExists($this->root . '/nope.txt'));
    }

    /**
     * Regression: canonicalizeAndCheckFileIdentifier() strips the trailing
     * slash, so a guard placed after it never fires.
     */
    public function testFolderIdentifierIsNeverAFile(): void
    {
        $this->seedTree();

        self::assertFalse($this->driver->fileExists($this->root . '/'));
        self::assertFalse($this->driver->fileExists($this->root . '/images/'));
        self::assertFalse($this->driver->fileExists('/'));
    }

    public function testExistenceChecksInsideAFolder(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->fileExistsInFolder('readme.txt', $this->root . '/'));
        self::assertFalse($this->driver->fileExistsInFolder('nope.txt', $this->root . '/'));
        self::assertTrue($this->driver->folderExistsInFolder('images', $this->root . '/'));
        self::assertFalse($this->driver->folderExistsInFolder('nope', $this->root . '/'));
    }

    public function testFolderWithContentIsNotEmpty(): void
    {
        $this->seedTree();

        self::assertFalse($this->driver->isFolderEmpty($this->root . '/'));
        self::assertFalse($this->driver->isFolderEmpty($this->root . '/images/'));
    }

    // =======================================================================
    // Identifiers
    // =======================================================================

    public function testBuildsIdentifiersInsideAFolder(): void
    {
        self::assertSame(
            $this->root . '/readme.txt',
            $this->driver->getFileInFolder('readme.txt', $this->root . '/')
        );
        self::assertSame(
            $this->root . '/images/',
            $this->driver->getFolderInFolder('images', $this->root . '/')
        );
    }

    /**
     * The interface requires TRUE when the identifier is the container itself,
     * otherwise a file mount cannot access its own root folder.
     */
    public function testIsWithinMatchesTheContainerItself(): void
    {
        self::assertTrue($this->driver->isWithin($this->root . '/', $this->root . '/readme.txt'));
        self::assertTrue($this->driver->isWithin($this->root . '/', $this->root . '/'));
        self::assertTrue($this->driver->isWithin('/', '/'));
        self::assertFalse($this->driver->isWithin($this->root . '/images/', $this->root . '/readme.txt'));
    }

    public function testSanitizesFileNames(): void
    {
        self::assertSame('Bild_mit_Leerzeichen.PNG', $this->driver->sanitizeFileName('Bild mit Leerzeichen.PNG'));
        self::assertSame('a_b', $this->driver->sanitizeFileName('a/b'));
    }

    // =======================================================================
    // Listing
    // =======================================================================

    public function testListsOnlyTheCurrentLevel(): void
    {
        $this->seedTree();

        self::assertSame(
            [$this->root . '/readme.txt'],
            array_values($this->driver->getFilesInFolder($this->root . '/'))
        );
        self::assertSame(
            [$this->root . '/images/icon.svg', $this->root . '/images/logo.png'],
            array_values($this->driver->getFilesInFolder($this->root . '/images/'))
        );
    }

    /**
     * No object represents a folder here: they are derived from the common
     * prefixes the API reports when a delimiter is given.
     */
    public function testDerivesSubFoldersFromCommonPrefixes(): void
    {
        $this->seedTree();

        self::assertSame(
            [$this->root . '/docs/', $this->root . '/images/'],
            array_values($this->driver->getFoldersInFolder($this->root . '/'))
        );
    }

    /**
     * ResourceStorage removes the processing folder by array key, so a listing
     * that reindexes its result would break that.
     */
    public function testListingIsKeyedByIdentifier(): void
    {
        $this->seedTree();
        $files = $this->driver->getFilesInFolder($this->root . '/images/');

        self::assertArrayHasKey($this->root . '/images/logo.png', $files);
        self::assertSame(array_keys($files), array_values($files));
    }

    public function testCountsFilesAndFolders(): void
    {
        $this->seedTree();

        self::assertSame(1, $this->driver->countFilesInFolder($this->root . '/'));
        self::assertSame(4, $this->driver->countFilesInFolder($this->root . '/', true));
        self::assertSame(2, $this->driver->countFoldersInFolder($this->root . '/'));
    }

    /**
     * A recursive listing has no common prefixes, so intermediate folders have
     * to be reconstructed from the keys. Without that, "docs/deep/" is lost.
     */
    public function testRecursiveListingReconstructsIntermediateFolders(): void
    {
        $this->seedTree();

        self::assertSame(
            [$this->root . '/docs/', $this->root . '/docs/deep/', $this->root . '/images/'],
            array_values($this->driver->getFoldersInFolder($this->root . '/', 0, 0, true))
        );
    }

    public function testPagingLimitsTheResult(): void
    {
        $this->seedTree();

        self::assertCount(1, $this->driver->getFilesInFolder($this->root . '/', 0, 1, true));
        self::assertCount(2, $this->driver->getFilesInFolder($this->root . '/', 1, 2, true));
    }

    public function testFilterCallbackCanExcludeItems(): void
    {
        $this->seedTree();
        $excludeSvg = static fn(string $name): int|bool => str_ends_with($name, '.svg') ? -1 : true;

        self::assertSame(
            [$this->root . '/images/logo.png'],
            array_values($this->driver->getFilesInFolder($this->root . '/images/', 0, 0, false, [$excludeSvg]))
        );
    }

    // =======================================================================
    // Reading
    // =======================================================================

    public function testReadsFileContents(): void
    {
        $this->seedTree();

        self::assertSame(self::README, $this->driver->getFileContents($this->root . '/readme.txt'));
    }

    public function testStreamsFileContentsToOutput(): void
    {
        $this->seedTree();

        ob_start();
        $this->driver->dumpFileContents($this->root . '/readme.txt');

        self::assertSame(self::README, ob_get_clean());
    }

    public function testReportsFileInformation(): void
    {
        $this->seedTree();
        $info = $this->driver->getFileInfoByIdentifier($this->root . '/readme.txt');

        self::assertSame(strlen(self::README), $info['size']);
        self::assertSame('readme.txt', $info['name']);
        self::assertSame('txt', $info['extension']);
        self::assertSame($this->root . '/readme.txt', $info['identifier']);
        self::assertGreaterThan(0, $info['mtime']);
    }

    public function testReportsOnlyTheRequestedProperties(): void
    {
        $this->seedTree();

        self::assertSame(
            ['size', 'name'],
            array_keys($this->driver->getFileInfoByIdentifier($this->root . '/readme.txt', ['size', 'name']))
        );
    }

    public function testReportsFolderInformation(): void
    {
        $this->seedTree();
        $info = $this->driver->getFolderInfoByIdentifier($this->root . '/images/');

        self::assertSame('images', $info['name']);
        self::assertSame($this->root . '/images/', $info['identifier']);
    }

    public function testFileInfoForMissingFileThrows(): void
    {
        $this->expectException(FileDoesNotExistException::class);

        $this->driver->getFileInfoByIdentifier($this->root . '/nope.txt');
    }

    /**
     * Hashing contents would mean downloading every file TYPO3 indexes, so the
     * identifier hash is the default.
     */
    public function testHashesTheIdentifierByDefault(): void
    {
        $this->seedTree();

        self::assertSame(
            sha1($this->root . '/readme.txt'),
            $this->driver->hash($this->root . '/readme.txt', 'md5')
        );
    }

    // =======================================================================
    // Writing
    // =======================================================================

    public function testCreatesAnEmptyFolder(): void
    {
        $created = $this->driver->createFolder('fresh', $this->root . '/');

        self::assertSame($this->root . '/fresh/', $created);
        self::assertTrue($this->driver->folderExists($created));
        self::assertTrue($this->driver->isFolderEmpty($created), 'the folder marker is not content');
    }

    public function testCreatesAndFillsAFile(): void
    {
        $file = $this->driver->createFile('note.txt', $this->root . '/');

        self::assertSame($this->root . '/note.txt', $file);
        self::assertSame(21, $this->driver->setFileContents($file, 'written by the driver'));
        self::assertSame('written by the driver', $this->driver->getFileContents($file));
    }

    public function testCopiesAFileWithinTheStorage(): void
    {
        $this->seedTree();
        $copy = $this->driver->copyFileWithinStorage($this->root . '/readme.txt', $this->root . '/', 'copy.txt');

        self::assertSame($this->root . '/copy.txt', $copy);
        self::assertSame(self::README, $this->driver->getFileContents($copy));
        self::assertTrue($this->driver->fileExists($this->root . '/readme.txt'), 'the source survives a copy');
    }

    public function testRenamingRemovesTheSource(): void
    {
        $this->seedTree();
        $renamed = $this->driver->renameFile($this->root . '/readme.txt', 'renamed.txt');

        self::assertSame($this->root . '/renamed.txt', $renamed);
        self::assertTrue($this->driver->fileExists($renamed));
        self::assertFalse($this->driver->fileExists($this->root . '/readme.txt'));
    }

    public function testAddsALocalFileAndRemovesTheOriginal(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'fals3') . '.txt';
        file_put_contents($local, 'uploaded from disk');

        $added = $this->driver->addFile($local, $this->root . '/', 'uploaded.txt');

        self::assertSame('uploaded from disk', $this->driver->getFileContents($added));
        self::assertFileDoesNotExist($local);
        self::assertSame('text/plain', $this->driver->getFileInfoByIdentifier($added)['mimetype']);
    }

    public function testKeepsTheOriginalWhenAsked(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'fals3') . '.txt';
        file_put_contents($local, 'kept');

        try {
            $this->driver->addFile($local, $this->root . '/', 'kept.txt', false);

            self::assertFileExists($local);
        } finally {
            @unlink($local);
        }
    }

    public function testReplacesAFile(): void
    {
        $this->seedTree();
        $local = tempnam(sys_get_temp_dir(), 'fals3') . '.txt';
        file_put_contents($local, 'replacement');

        try {
            self::assertTrue($this->driver->replaceFile($this->root . '/readme.txt', $local));
            self::assertSame('replacement', $this->driver->getFileContents($this->root . '/readme.txt'));
        } finally {
            @unlink($local);
        }
    }

    public function testDeletesAFile(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->deleteFile($this->root . '/readme.txt'));
        self::assertFalse($this->driver->fileExists($this->root . '/readme.txt'));
    }

    public function testDeletesAFolderRecursively(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->deleteFolder($this->root . '/images/', true));
        self::assertFalse($this->driver->folderExists($this->root . '/images/'));
        self::assertTrue($this->driver->fileExists($this->root . '/readme.txt'), 'siblings survive');
    }

    public function testRefusesToDeleteANonEmptyFolder(): void
    {
        $this->seedTree();

        $this->expectException(\RuntimeException::class);
        $this->driver->deleteFolder($this->root . '/images/');
    }

    /**
     * The mapping tells FAL which sys_file records to rewrite. A folder marker
     * has no record, so it is copied but must not appear in the mapping.
     */
    public function testMovingAFolderReportsOnlyRealFiles(): void
    {
        $this->seedTree();
        $this->driver->createFolder('marker', $this->root . '/images/');

        $mapping = $this->driver->moveFolderWithinStorage($this->root . '/images/', $this->root . '/', 'moved');

        self::assertSame(
            [
                $this->root . '/images/icon.svg' => $this->root . '/moved/icon.svg',
                $this->root . '/images/logo.png' => $this->root . '/moved/logo.png',
            ],
            $mapping
        );
        self::assertFalse($this->driver->folderExists($this->root . '/images/'));
        self::assertTrue($this->driver->folderExists($this->root . '/moved/'));
    }

    public function testCopiesAFolderWithinTheStorage(): void
    {
        $this->seedTree();

        self::assertTrue($this->driver->copyFolderWithinStorage($this->root . '/images/', $this->root . '/', 'copy'));
        self::assertTrue($this->driver->folderExists($this->root . '/images/'), 'the source survives a copy');
        self::assertSame(
            ['icon.svg', 'logo.png'],
            array_map('basename', array_values($this->driver->getFilesInFolder($this->root . '/copy/')))
        );
    }

    /**
     * sanitizeFileName() turns a slash into an underscore, so a recursive name
     * has to be split into segments first.
     */
    public function testCreatesNestedFoldersWhenRecursive(): void
    {
        $nested = $this->driver->createFolder('jahr/2026/bilder', $this->root . '/', true);

        self::assertSame($this->root . '/jahr/2026/bilder/', $nested);
        self::assertTrue($this->driver->folderExists($nested));
    }

    public function testTreatsASlashAsPartOfTheNameWhenNotRecursive(): void
    {
        self::assertSame(
            $this->root . '/jahr_2026/',
            $this->driver->createFolder('jahr/2026', $this->root . '/')
        );
    }

    // =======================================================================
    // Local processing
    // =======================================================================

    public function testProvidesALocalCopyForProcessing(): void
    {
        $this->seedTree();
        $local = $this->driver->getFileForLocalProcessing($this->root . '/readme.txt', false);

        self::assertFileExists($local);
        self::assertSame(self::README, file_get_contents($local));
        self::assertSame('txt', pathinfo($local, PATHINFO_EXTENSION), 'tools decide by extension');
    }

    /**
     * A read-only copy may be reused, a writable one never: the caller is
     * allowed to modify it, and the change must not leak into later calls.
     */
    public function testReusesReadOnlyCopiesButNotWritableOnes(): void
    {
        $this->seedTree();
        $readOnly = $this->driver->getFileForLocalProcessing($this->root . '/readme.txt', false);

        self::assertSame($readOnly, $this->driver->getFileForLocalProcessing($this->root . '/readme.txt', false));
        self::assertNotSame($readOnly, $this->driver->getFileForLocalProcessing($this->root . '/readme.txt', true));
    }

    public function testLocalProcessingOfAMissingFileThrows(): void
    {
        $this->expectException(FileDoesNotExistException::class);

        $this->driver->getFileForLocalProcessing($this->root . '/nope.txt');
    }

}
