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

use MARCMAERDIAN\FalS3Driver\Service\MimeTypeGuesser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MimeTypeGuesser::class)]
final class MimeTypeGuesserTest extends TestCase
{
    private MimeTypeGuesser $guesser;

    protected function setUp(): void
    {
        $this->guesser = new MimeTypeGuesser();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fileNames(): array
    {
        return [
            'png' => ['logo.png', 'image/png'],
            'jpeg' => ['photo.jpg', 'image/jpeg'],
            'pdf' => ['manual.pdf', 'application/pdf'],
            'uppercase extension' => ['LOGO.PNG', 'image/png'],
            'path is irrelevant' => ['/a/b/logo.png', 'image/png'],
        ];
    }

    #[DataProvider('fileNames')]
    public function testGuessesFromExtension(string $fileName, string $expected): void
    {
        self::assertSame($expected, $this->guesser->guess($fileName));
    }

    public function testFallsBackWhenThereIsNoExtension(): void
    {
        self::assertSame('application/octet-stream', $this->guesser->guess('README'));
    }

    public function testFallsBackOnUnknownExtension(): void
    {
        self::assertSame('application/octet-stream', $this->guesser->guess('data.qqq'));
    }

    /**
     * Content beats the file name: an editor renaming "shell.php" to
     * "holiday.jpg" must not get it served as an image.
     */
    public function testLocalFileContentWinsOverTheExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'guess') . '.jpg';
        file_put_contents($path, "plain text, definitely not a jpeg\n");

        try {
            self::assertStringStartsWith('text/', $this->guesser->guess('holiday.jpg', $path));
        } finally {
            @unlink($path);
        }
    }

    public function testUnreadableLocalPathFallsBackToTheExtension(): void
    {
        self::assertSame(
            'image/png',
            $this->guesser->guess('logo.png', '/does/not/exist.png')
        );
    }
}
