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

use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use MM\FalS3Driver\Configuration\StorageConfiguration;
use Psr\Http\Message\StreamInterface;

/**
 * Everything this extension does over the S3 API.
 *
 * The repository knows only object keys, never FAL identifiers, and it keeps no
 * state of its own. That split is what allows the driver above it to be read as
 * plain FAL logic, without AWS vocabulary in between.
 */
final class S3ObjectRepository
{
    /**
     * Files above this size are uploaded in parts. A single PutObject is capped
     * at 5 GB by the S3 API, and a long running upload of a large file is far
     * more likely to survive when it can retry individual parts.
     */
    private const MULTIPART_THRESHOLD = 64 * 1024 * 1024;

    public function __construct(
        private readonly S3Client $client,
        private readonly StorageConfiguration $config,
        private int $multipartThreshold = self::MULTIPART_THRESHOLD,
    ) {}

    public static function fromConfiguration(StorageConfiguration $config): self
    {
        $options = [
            'version' => 'latest',
            // Providers without regions (R2, MinIO) still need a value here,
            // because the SDK uses it when calculating the request signature.
            'region' => $config->region,
            'endpoint' => $config->endpoint,
            'use_path_style_endpoint' => $config->usePathStyleEndpoint,
            'credentials' => [
                'key' => $config->accessKeyId,
                'secret' => $config->secretAccessKey,
            ],
        ];

        if ($config->compatibilityMode) {
            // Most non-AWS providers do not implement the integrity checksums
            // the SDK sends by default and answer with 501 Not Implemented.
            $options['request_checksum_calculation'] = 'when_required';
            $options['response_checksum_validation'] = 'when_required';
        }

        return new self(new S3Client($options), $config);
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExistV2($this->config->bucket, $key);
    }

    /**
     * @return array<string, mixed>|null Null when the object is not there
     */
    public function head(string $key): ?array
    {
        try {
            return $this->client->headObject([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
            ])->toArray();
        } catch (AwsException) {
            return null;
        }
    }

    public function getContents(string $key): string
    {
        return (string)$this->getBody($key);
    }

    public function getBody(string $key): StreamInterface|string|null
    {
        return $this->client->getObject([
            'Bucket' => $this->config->bucket,
            'Key' => $key,
        ])['Body'] ?? null;
    }

    public function download(string $key, string $targetPath): bool
    {
        try {
            $this->client->getObject([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'SaveAs' => $targetPath,
            ]);
        } catch (AwsException) {
            @unlink($targetPath);

            return false;
        }

        // A failed SaveAs can leave the error body behind instead of the file.
        return is_file($targetPath);
    }

    // -----------------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------------

    public function hasAnyObject(string $prefix): bool
    {
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->config->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 1,
        ]);

        return (int)($result['KeyCount'] ?? 0) > 0;
    }

    /**
     * @return array<int, array<string, mixed>> Raw Contents entries
     */
    public function listFirstObjects(string $prefix, int $limit): array
    {
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->config->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => $limit,
        ]);

        return $result['Contents'] ?? [];
    }

    /**
     * Pages through a prefix. The paginator hides the limit of 1000 keys per
     * response; without it a folder would silently lose its 1001st file.
     *
     * @return iterable<array<string, mixed>> Raw response pages
     */
    public function listPages(string $prefix, bool $withDelimiter): iterable
    {
        $arguments = ['Bucket' => $this->config->bucket, 'Prefix' => $prefix];
        if ($withDelimiter) {
            // Without a delimiter the API returns every object below the
            // prefix. With it, anything deeper is folded into CommonPrefixes.
            $arguments['Delimiter'] = '/';
        }

        return $this->client->getPaginator('ListObjectsV2', $arguments);
    }

    /**
     * @return array<int, string>
     */
    public function listKeys(string $prefix): array
    {
        $keys = [];
        foreach ($this->listPages($prefix, false) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = (string)$object['Key'];
            }
        }

        return $keys;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    public function put(string $key, string $body, string $mimeType): void
    {
        $this->client->putObject([
            'Bucket' => $this->config->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $mimeType,
        ] + $this->getUploadOptions());
    }

    public function upload(string $localFilePath, string $key, string $mimeType): void
    {
        $parameters = ['ContentType' => $mimeType] + $this->getUploadOptions();

        if ((int)filesize($localFilePath) > $this->multipartThreshold) {
            (new MultipartUploader($this->client, $localFilePath, [
                'bucket' => $this->config->bucket,
                'key' => $key,
                'params' => $parameters,
            ]))->upload();

            return;
        }

        // A multipart upload cannot handle zero byte files at all, and for
        // small ones the extra round trips are not worth it.
        $this->client->putObject([
            'Bucket' => $this->config->bucket,
            'Key' => $key,
            'SourceFile' => $localFilePath,
        ] + $parameters);
    }

    /**
     * MetadataDirective REPLACE is essential: without it S3 keeps the source
     * object's headers and silently ignores the content type and cache control
     * passed here.
     */
    public function copy(string $sourceKey, string $targetKey, ?string $mimeType = null): void
    {
        $arguments = [
            'Bucket' => $this->config->bucket,
            'Key' => $targetKey,
            'CopySource' => $sourceKey,
        ] + $this->getUploadOptions();

        if ($mimeType !== null) {
            $arguments['MetadataDirective'] = 'REPLACE';
            $arguments['ContentType'] = $mimeType;
        }

        $this->client->copyObject($arguments);
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->config->bucket, 'Key' => $key]);
    }

    /**
     * @param array<int, string> $keys
     */
    public function deleteMany(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        // DeleteObjects accepts at most 1000 keys per call.
        foreach (array_chunk($keys, 1000) as $chunk) {
            $this->client->deleteObjects([
                'Bucket' => $this->config->bucket,
                'Delete' => ['Objects' => array_map(static fn(string $key): array => ['Key' => $key], $chunk)],
            ]);
        }
    }

    /**
     * Cache-Control for uploaded objects. Without it the CDN in front of the
     * bucket decides on its own how long it keeps a file.
     *
     * @return array<string, string>
     */
    private function getUploadOptions(): array
    {
        return $this->config->cacheControlMaxAge > 0
            ? ['CacheControl' => 'max-age=' . $this->config->cacheControlMaxAge]
            : [];
    }
}
