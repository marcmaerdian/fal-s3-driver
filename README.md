# TYPO3 FAL Driver for S3-compatible Object Storage

A File Abstraction Layer (FAL) driver that lets TYPO3 use any S3-compatible
bucket as a file storage — upload, download, link and image processing.

Tested against **Cloudflare R2**. Should work with Hetzner Object Storage,
MinIO, Backblaze B2, Wasabi and AWS S3 itself, since the endpoint, region,
addressing style and checksum behaviour are all configurable.

**Status:** work in progress, not production ready.

## Requirements

- TYPO3 14.3+
- PHP 8.2+
- A bucket and an S3 API token from your provider

## Installation

Not on Packagist yet. Add the repository manually:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/marcmaerdian/fal-s3-driver.git" }
    ]
}
```

```bash
composer require marcmaerdian/fal-s3-driver
```

## Configuration

Create a file storage in the TYPO3 backend and pick the driver
**S3-compatible storage**.

| Field | Notes |
|-------|-------|
| Endpoint URL | API endpoint, **not** the URL visitors use |
| Region | `auto` works for providers without regions |
| Bucket name | |
| Access Key ID / Secret | S3 API token from your provider |
| Public base URL | Custom domain, `r2.dev` URL or CDN — where visitors fetch files |
| Base path | Optional prefix inside the bucket |
| Use path-style endpoint | Required by R2 and MinIO; off for AWS S3 |
| Non-AWS compatibility mode | Disables integrity checksums that only AWS implements |

### Keeping credentials out of the database

Everything configured in the backend can be overridden from
`config/system/settings.php`, which keeps secrets out of `sys_file_storage` and
lets one storage record be deployed to several environments:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fal_s3_driver'] = [
    // applies to every storage using this driver
    'storage' => [
        'accessKeyId' => getenv('S3_ACCESS_KEY_ID'),
        'secretAccessKey' => getenv('S3_SECRET_ACCESS_KEY'),
    ],
    // applies only to sys_file_storage.uid = 3 and wins over the block above
    'storage_3' => [
        'endpoint' => getenv('S3_ENDPOINT'),
        'bucket' => getenv('S3_BUCKET'),
        'publicBaseUrl' => getenv('S3_PUBLIC_BASE_URL'),
    ],
];
```

Fields that are not listed keep the value stored in the backend.

### Endpoint examples

| Provider | Endpoint |
|----------|----------|
| Cloudflare R2 | `https://<account-id>.r2.cloudflarestorage.com` |
| Hetzner Object Storage | `https://<location>.your-objectstorage.com` |
| MinIO (local) | `http://localhost:9000` |
| AWS S3 | `https://s3.<region>.amazonaws.com` |

## Development

```bash
composer install
composer test
```

The default test run is **completely offline**: no network, no credentials, no
bucket. The driver is exercised against an in-memory object store that mirrors
the S3 response shapes, so every line of driver logic is the production one and
only the destination of the bytes is swapped.

| Command | What it runs |
|---------|--------------|
| `composer test` | Everything offline: pure classes plus full driver behaviour |
| `composer test:unit` | Only the classes without I/O |
| `composer test:functional` | Only the driver, against the in-memory store |
| `composer test:bucket` | The same behaviour against a **real bucket** |

### Running the bucket tests

These are excluded from the default run by the PHPUnit group `bucket`, and they
skip themselves when no credentials are present. Use a scratch bucket: the tests
write and delete objects. Everything is created below a randomly named prefix
and removed afterwards.

Credentials come from the environment, or from a `.env.local` that is never
committed. Set `S3_ENV` to pick a suffix, so several environments can coexist:

```bash
export S3_ENDPOINT_INT="..." S3_BUCKET_INT="..." S3_ACCESS_KEY_ID_INT="..." S3_SECRET_ACCESS_KEY_INT="..."
S3_ENV=INT composer test:bucket
```

Without `S3_ENV` the unsuffixed names (`S3_ENDPOINT`, …) are used.

### Checking a connection by hand

```bash
S3_ENV=INT php bin/s3-smoketest.php
```

Prints the resolved settings, lists the bucket and reports the HTTP status and
provider error code when something is wrong. Add `S3_WRITE_TEST=1` to verify a
full put/get/delete round trip, which is what catches providers rejecting the
AWS SDK's default checksums.

## License

GPL-2.0-or-later
