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

namespace MARCMAERDIAN\FalS3Driver\Tests\Fake;

use MARCMAERDIAN\FalS3Driver\Configuration\StorageConfiguration;
use MARCMAERDIAN\FalS3Driver\Service\ObjectRepository;
use Psr\Http\Message\StreamInterface;

/**
 * An object store in an array.
 *
 * Deliberately not a directory on disk: an object store is flat, a key is just
 * a string that may happen to contain slashes. Backing the double with real
 * folders would reintroduce filesystem semantics and could hide bugs in exactly
 * the folder emulation this driver exists to provide.
 *
 * The response shapes mirror what the S3 API returns, because that is the
 * contract the driver is written against.
 */
final class InMemoryObjectRepository implements ObjectRepository
{
    /**
     * @var array<string, array{body: string, mime: string, mtime: int, cacheControl: string}>
     */
    private array $objects = [];

    /**
     * Counts calls per method, so a test can assert that a listing really did
     * spare the driver a request per file.
     *
     * @var array<string, int>
     */
    public array $calls = [];

    public function __construct(private readonly StorageConfiguration $config) {}

    public function exists(string $key): bool
    {
        $this->record(__FUNCTION__);

        return isset($this->objects[$key]);
    }

    public function head(string $key): ?array
    {
        $this->record(__FUNCTION__);

        if (!isset($this->objects[$key])) {
            return null;
        }

        $object = $this->objects[$key];

        return [
            'ContentLength' => strlen($object['body']),
            'LastModified' => (new \DateTimeImmutable())->setTimestamp($object['mtime']),
            'ContentType' => $object['mime'],
            'CacheControl' => $object['cacheControl'],
        ];
    }

    public function getContents(string $key): string
    {
        $this->record(__FUNCTION__);

        return $this->objects[$key]['body'] ?? '';
    }

    public function getBody(string $key): StreamInterface|string|null
    {
        $this->record(__FUNCTION__);

        return $this->objects[$key]['body'] ?? null;
    }

    public function download(string $key, string $targetPath): bool
    {
        $this->record(__FUNCTION__);

        if (!isset($this->objects[$key])) {
            return false;
        }

        return file_put_contents($targetPath, $this->objects[$key]['body']) !== false;
    }

    public function hasAnyObject(string $prefix): bool
    {
        $this->record(__FUNCTION__);

        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function listFirstObjects(string $prefix, int $limit): array
    {
        $this->record(__FUNCTION__);

        $found = [];
        foreach ($this->sortedKeys() as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $found[] = $this->toContentsEntry($key);
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function listPages(string $prefix, bool $withDelimiter): iterable
    {
        $this->record(__FUNCTION__);

        $contents = [];
        $commonPrefixes = [];

        foreach ($this->sortedKeys() as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $remainder = substr($key, strlen($prefix));

            // With a delimiter, anything below the current level is folded into
            // a common prefix instead of being listed as an object.
            if ($withDelimiter && str_contains($remainder, '/')) {
                $commonPrefix = $prefix . substr($remainder, 0, strpos($remainder, '/') + 1);
                $commonPrefixes[$commonPrefix] = ['Prefix' => $commonPrefix];

                continue;
            }

            $contents[] = $this->toContentsEntry($key);
        }

        $page = [];
        if ($contents !== []) {
            $page['Contents'] = $contents;
        }
        if ($commonPrefixes !== []) {
            $page['CommonPrefixes'] = array_values($commonPrefixes);
        }

        // One page is enough: paging is the SDK's job, and the driver only ever
        // iterates whatever it is handed.
        return [$page];
    }

    public function listKeys(string $prefix): array
    {
        $this->record(__FUNCTION__);

        return array_values(array_filter(
            $this->sortedKeys(),
            static fn(string $key): bool => str_starts_with($key, $prefix)
        ));
    }

    public function put(string $key, string $body, string $mimeType): void
    {
        $this->record(__FUNCTION__);
        $this->write($key, $body, $mimeType);
    }

    public function upload(string $localFilePath, string $key, string $mimeType): void
    {
        $this->record(__FUNCTION__);
        $this->write($key, (string)file_get_contents($localFilePath), $mimeType);
    }

    public function copy(string $sourceKey, string $targetKey, ?string $mimeType = null): void
    {
        $this->record(__FUNCTION__);

        if (!isset($this->objects[$sourceKey])) {
            return;
        }

        $source = $this->objects[$sourceKey];
        // Without an explicit content type S3 keeps the source's metadata,
        // which is exactly what MetadataDirective REPLACE exists to override.
        $this->write($targetKey, $source['body'], $mimeType ?? $source['mime']);
    }

    public function delete(string $key): void
    {
        $this->record(__FUNCTION__);
        unset($this->objects[$key]);
    }

    public function deleteMany(array $keys): void
    {
        $this->record(__FUNCTION__);

        foreach ($keys as $key) {
            unset($this->objects[$key]);
        }
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * Writes without going through the driver, to set up a scenario.
     */
    public function seed(string $key, string $body, string $mimeType = 'application/octet-stream'): void
    {
        $this->write($key, $body, $mimeType);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return $this->sortedKeys();
    }

    public function callCount(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function resetCallCount(): void
    {
        $this->calls = [];
    }

    private function write(string $key, string $body, string $mimeType): void
    {
        $this->objects[$key] = [
            'body' => $body,
            'mime' => $mimeType,
            'mtime' => time(),
            'cacheControl' => $this->config->cacheControlMaxAge > 0
                ? 'max-age=' . $this->config->cacheControlMaxAge
                : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toContentsEntry(string $key): array
    {
        return [
            'Key' => $key,
            'Size' => strlen($this->objects[$key]['body']),
            'LastModified' => (new \DateTimeImmutable())->setTimestamp($this->objects[$key]['mtime']),
        ];
    }

    /**
     * S3 returns keys in lexicographic order and parts of the driver rely on it.
     *
     * @return array<int, string>
     */
    private function sortedKeys(): array
    {
        $keys = array_keys($this->objects);
        sort($keys);

        return $keys;
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
