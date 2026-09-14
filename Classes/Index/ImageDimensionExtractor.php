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

namespace MARCMAERDIAN\FalS3Driver\Index;

use MARCMAERDIAN\FalS3Driver\Driver\S3Driver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads width and height of images stored in the bucket.
 *
 * TYPO3 determines image dimensions itself only for local storages. The core
 * says so in Indexer::extractRequiredMetaData():
 *
 *   "prevent doing this for remote storages, remote storages must provide the
 *    data with extractors"
 *
 * Without this class width and height stay 0 in sys_file_metadata, and cropping,
 * responsive images and the dimensions shown in the backend all break.
 */
final class ImageDimensionExtractor implements ExtractorInterface
{
    /**
     * Dimensions measured in this request, keyed by file identifier. Reading
     * them means downloading the image, so doing it twice is worth avoiding.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $measured = [];

    public function getFileTypeRestrictions(): array
    {
        return [FileType::IMAGE->value];
    }

    public function getDriverRestrictions(): array
    {
        return [S3Driver::DRIVER_TYPE];
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getExecutionPriority(): int
    {
        return 50;
    }

    public function canProcess(File $file): bool
    {
        return $file->isImage() && $file->getStorage()->getDriverType() === S3Driver::DRIVER_TYPE;
    }

    /**
     * @param array<string, mixed> $previousExtractedData
     * @return array<string, mixed>
     */
    public function extractMetaData(File $file, array $previousExtractedData = []): array
    {
        // Another extractor may have been faster; do not download for nothing.
        if (!empty($previousExtractedData['width']) && !empty($previousExtractedData['height'])) {
            return $previousExtractedData;
        }

        $dimensions = $this->measure($file);
        if ($dimensions === null) {
            return $previousExtractedData;
        }

        $previousExtractedData['width'] = $dimensions[0];
        $previousExtractedData['height'] = $dimensions[1];

        return $previousExtractedData;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function measure(File $file): ?array
    {
        $identifier = $file->getIdentifier();

        if (isset($this->measured[$identifier])) {
            return $this->measured[$identifier];
        }

        // false: a read-only copy, which the driver may serve from its own
        // per-request cache instead of downloading again.
        $localPath = $file->getForLocalProcessing(false);
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $localPath);

        $width = $imageInfo->getWidth();
        $height = $imageInfo->getHeight();

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return $this->measured[$identifier] = [$width, $height];
    }
}
