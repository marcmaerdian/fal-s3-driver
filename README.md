# TYPO3 FAL Driver for Cloudflare R2

A File Abstraction Layer (FAL) driver that lets TYPO3 use a Cloudflare R2
bucket as a file storage — upload, download, link and image processing.

**Status:** work in progress, not production ready.

## Requirements

- TYPO3 14.3+
- PHP 8.2+
- A Cloudflare R2 bucket with an S3 API token

## Installation

Not on Packagist yet. Add the repository manually:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/marcmaerdian/fal-r2-driver.git" }
    ]
}