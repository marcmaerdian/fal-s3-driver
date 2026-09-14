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

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

require __DIR__ . '/../vendor/autoload.php';

$config = [
    'endpoint' => getenv('S3_ENDPOINT') ?: '',
    'bucket' => getenv('S3_BUCKET') ?: '',
    'key' => getenv('S3_ACCESS_KEY_ID') ?: '',
    'secret' => getenv('S3_SECRET_ACCESS_KEY') ?: '',
];

$envNames = [
    'endpoint' => 'S3_ENDPOINT',
    'bucket' => 'S3_BUCKET',
    'key' => 'S3_ACCESS_KEY_ID',
    'secret' => 'S3_SECRET_ACCESS_KEY',
];

foreach ($config as $name => $value) {
    if ($value === '') {
        fwrite(STDERR, sprintf("Missing environment variable: %s\n", $envNames[$name]));
        exit(1);
    }
}

$region = getenv('S3_REGION') ?: 'auto';
$pathStyle = getenv('S3_PATH_STYLE') !== '0';
$compatibility = getenv('S3_COMPATIBILITY') !== '0';

$options = [
    'version' => 'latest',
    'region' => $region,
    'endpoint' => rtrim($config['endpoint'], '/'),
    'use_path_style_endpoint' => $pathStyle,
    'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
];

if ($compatibility) {
    $options['request_checksum_calculation'] = 'when_required';
    $options['response_checksum_validation'] = 'when_required';
}

printf("Endpoint:   %s\n", $options['endpoint']);
printf("Region:     %s\n", $region);
printf("Bucket:     %s\n", $config['bucket']);
printf("Path style: %s\n", $pathStyle ? 'yes' : 'no');
printf("Compat:     %s\n\n", $compatibility ? 'yes' : 'no');

try {
    $result = (new S3Client($options))->listObjectsV2([
        'Bucket' => $config['bucket'],
        'MaxKeys' => 20,
    ]);
} catch (AwsException $e) {
    fwrite(STDERR, sprintf(
        "FAILED\n  HTTP status: %s\n  AWS code:    %s\n  Message:     %s\n",
        $e->getStatusCode() ?? 'n/a',
        $e->getAwsErrorCode() ?? 'n/a',
        $e->getAwsErrorMessage() ?? $e->getMessage()
    ));
    exit(1);
}

printf("Objects returned: %d\n", $result['KeyCount'] ?? 0);

foreach ($result['Contents'] ?? [] as $object) {
    printf("  %-60s %10d bytes\n", $object['Key'], $object['Size']);
}

echo "\nOK\n";
