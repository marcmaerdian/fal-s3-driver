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

namespace Marcmaerdian\FalS3Driver\Service;

/**
 * Translates between FAL identifiers and object keys.
 *
 * FAL addresses files as "/images/logo.png" and folders as "/images/", with the
 * trailing slash as the only thing telling them apart. An object store knows
 * neither: it has flat keys such as "fileadmin/images/logo.png". This class is
 * the single place where the two worlds meet, and it performs no I/O at all.
 */
final readonly class ObjectKeyMapper
{
    public function __construct(
        private string $bucket,
        private string $basePath,
    ) {}

    /**
     * "/images/logo.png" with base path "fileadmin"
     *   becomes "fileadmin/images/logo.png".
     *
     * Only leading slashes are stripped: a trailing one marks a folder and has
     * to survive.
     */
    public function toObjectKey(string $identifier): string
    {
        $key = ltrim($identifier, '/');

        return $this->basePath !== '' ? $this->basePath . '/' . $key : $key;
    }

    /**
     * Inverse of toObjectKey().
     */
    public function toIdentifier(string $key): string
    {
        if ($this->basePath !== '' && str_starts_with($key, $this->basePath . '/')) {
            $key = substr($key, strlen($this->basePath) + 1);
        }

        return '/' . ltrim($key, '/');
    }

    /**
     * The CopySource value for copyObject(). AWS expects it URL encoded, yet
     * the slashes separating the path segments must survive, so each segment is
     * encoded on its own.
     */
    public function toCopySource(string $key): string
    {
        return $this->bucket . '/' . $this->encodeSegments($key);
    }

    /**
     * Encodes a key for use in a public URL. rawurlencode() over the whole
     * string would turn every slash into %2F and destroy the path.
     */
    public function encodeSegments(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }
}
