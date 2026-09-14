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

namespace MARCMAERDIAN\FalS3Driver\Configuration;

/**
 * The settings of one file storage, read from the FlexForm.
 *
 * This is the single place where FlexForm field names appear in PHP. A field
 * that is renamed in the XML and not here produces a silent empty string, so
 * keeping the mapping in one class makes that mismatch easy to spot.
 */
final readonly class StorageConfiguration
{
    public function __construct(
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKeyId,
        public string $secretAccessKey,
        public string $publicBaseUrl,
        public string $basePath,
        public bool $usePathStyleEndpoint,
        public bool $compatibilityMode,
        public bool $useContentHash,
        public int $cacheControlMaxAge,
    ) {}

    /**
     * @param array<string, mixed> $configuration Raw FlexForm values
     */
    public static function fromArray(array $configuration): self
    {
        $string = static fn(string $key): string => trim((string)($configuration[$key] ?? ''));

        return new self(
            endpoint: rtrim($string('endpoint'), '/'),
            region: $string('region') !== '' ? $string('region') : 'auto',
            bucket: $string('bucket'),
            accessKeyId: $string('accessKeyId'),
            secretAccessKey: $string('secretAccessKey'),
            publicBaseUrl: rtrim($string('publicBaseUrl'), '/'),
            basePath: trim($string('basePath'), '/'),
            usePathStyleEndpoint: (bool)($configuration['usePathStyleEndpoint'] ?? true),
            compatibilityMode: (bool)($configuration['compatibilityMode'] ?? true),
            useContentHash: (bool)($configuration['useContentHash'] ?? false),
            cacheControlMaxAge: max(0, (int)($configuration['cacheControlMaxAge'] ?? 0)),
        );
    }

    /**
     * A storage may be saved half filled in while an editor is still working on
     * the form. Without these three values no connection can be made at all.
     */
    public function isComplete(): bool
    {
        return $this->endpoint !== '' && $this->accessKeyId !== '' && $this->secretAccessKey !== '';
    }
}
