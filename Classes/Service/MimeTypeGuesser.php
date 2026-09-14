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

namespace MM\FalS3Driver\Service;

use TYPO3\CMS\Core\Resource\MimeTypeDetector;

/**
 * Determines the content type to store alongside an object.
 *
 * Getting this right matters twice: the browser relies on it when the file is
 * delivered from the bucket, and TYPO3 uses it to pick icons and to filter.
 */
final class MimeTypeGuesser
{
    private const FALLBACK = 'application/octet-stream';

    /**
     * Inspects the local file when there is one, because its content is more
     * reliable than its name. Falls back to the extension otherwise, which is
     * all that is available for an object that only exists in the bucket.
     */
    public function guess(string $fileName, ?string $localFilePath = null): string
    {
        if ($localFilePath !== null && is_readable($localFilePath)) {
            $detected = @mime_content_type($localFilePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            return self::FALLBACK;
        }

        $candidates = (new MimeTypeDetector())->getMimeTypesForFileExtension($extension);

        return $candidates !== [] ? (string)reset($candidates) : self::FALLBACK;
    }
}
