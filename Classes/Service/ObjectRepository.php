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

namespace MARCMAERDIAN\FalS3Driver\Service;

use Psr\Http\Message\StreamInterface;

/**
 * Everything the driver needs from an object store.
 *
 * Splitting this off from the concrete S3 implementation is what allows the
 * driver to be tested without a bucket: the test suite runs the very same
 * assertions against an in-memory double.
 *
 * All keys are raw object keys, never FAL identifiers.
 */
interface ObjectRepository
{
    public function exists(string $key): bool;

    /**
     * Object metadata, or null when there is no such object. The keys follow
     * the S3 response: ContentLength, LastModified, ContentType, CacheControl.
     *
     * @return array<string, mixed>|null
     */
    public function head(string $key): ?array;

    public function getContents(string $key): string;

    public function getBody(string $key): StreamInterface|string|null;

    /**
     * @return bool False when the object could not be written to $targetPath
     */
    public function download(string $key, string $targetPath): bool;

    public function hasAnyObject(string $prefix): bool;

    /**
     * @return array<int, array<string, mixed>> At most $limit raw object entries
     */
    public function listFirstObjects(string $prefix, int $limit): array;

    /**
     * Pages through a prefix. With a delimiter, anything below the current
     * level is reported as CommonPrefixes instead of Contents.
     *
     * @return iterable<array{Contents?: array<int, array<string, mixed>>, CommonPrefixes?: array<int, array{Prefix: string}>}>
     */
    public function listPages(string $prefix, bool $withDelimiter): iterable;

    /**
     * @return array<int, string>
     */
    public function listKeys(string $prefix): array;

    public function put(string $key, string $body, string $mimeType): void;

    public function upload(string $localFilePath, string $key, string $mimeType): void;

    /**
     * @param string $sourceKey A plain object key, not an encoded CopySource
     */
    public function copy(string $sourceKey, string $targetKey, ?string $mimeType = null): void;

    public function delete(string $key): void;

    /**
     * @param array<int, string> $keys
     */
    public function deleteMany(array $keys): void;
}
