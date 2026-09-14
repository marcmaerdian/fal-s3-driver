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

/**
 * Remembers file information for the duration of one request.
 *
 * ListObjectsV2 already reports size and timestamp for every object it returns.
 * Without this cache, listing a folder of 100 files in the backend costs one
 * listing plus 100 HeadObject requests; with it, the listing alone is enough.
 *
 * The tricky part is not storing but discarding: a write has to invalidate the
 * object itself and, for a folder, everything below it.
 */
final class FileInfoCache
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $entries = [];

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $identifier): ?array
    {
        return $this->entries[$identifier] ?? null;
    }

    /**
     * @param array<string, mixed> $information
     */
    public function set(string $identifier, array $information): void
    {
        $this->entries[$identifier] = $information;
    }

    /**
     * Without an identifier the whole cache is dropped.
     */
    public function flush(string $identifier = ''): void
    {
        if ($identifier === '') {
            $this->entries = [];

            return;
        }

        unset($this->entries[$identifier]);

        // A folder identifier also invalidates everything it contains.
        $prefix = rtrim($identifier, '/') . '/';
        foreach (array_keys($this->entries) as $cached) {
            if (str_starts_with($cached, $prefix)) {
                unset($this->entries[$cached]);
            }
        }
    }
}
