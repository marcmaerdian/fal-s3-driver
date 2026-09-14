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
php bin/s3-smoketest.php
```

The smoke test reads its credentials from the environment:

```bash
export S3_ENDPOINT="..." S3_BUCKET="..." S3_ACCESS_KEY_ID="..." S3_SECRET_ACCESS_KEY="..."
```

## License

GPL-2.0-or-later
