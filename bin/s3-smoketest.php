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

// Optional convenience for local development: load .env.local from the
// project root, without overwriting variables already set in the shell.
$envFile = __DIR__ . '/../.env.local';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim(trim($value), "\"'");
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

// Pick an environment suffix so INT and PRD credentials can coexist in the
// same shell: S3_ENV=INT reads S3_ENDPOINT_INT, S3_BUCKET_INT and so on.
$environment = strtoupper(trim((string)(getenv('S3_ENV') ?: '')));
$suffix = $environment !== '' ? '_' . $environment : '';

$read = static function (string $name) use ($suffix): string {
    return trim((string)(getenv($name . $suffix) ?: ''));
};

$config = [
    'endpoint' => $read('S3_ENDPOINT'),
    'bucket' => $read('S3_BUCKET'),
    'key' => $read('S3_ACCESS_KEY_ID'),
    'secret' => $read('S3_SECRET_ACCESS_KEY'),
];

$envNames = [
    'endpoint' => 'S3_ENDPOINT',
    'bucket' => 'S3_BUCKET',
    'key' => 'S3_ACCESS_KEY_ID',
    'secret' => 'S3_SECRET_ACCESS_KEY',
];

foreach ($config as $name => $value) {
    if ($value === '') {
        fwrite(STDERR, sprintf("Missing environment variable: %s%s\n", $envNames[$name], $suffix));
        exit(1);
    }
}

$region = $read('S3_REGION') ?: 'auto';
$pathStyle = $read('S3_PATH_STYLE') !== '0';
$compatibility = $read('S3_COMPATIBILITY') !== '0';

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

printf("Environment: %s\n", $environment !== '' ? $environment : '(none)');
printf("Endpoint:   %s\n", $options['endpoint']);
printf("Region:     %s\n", $region);
printf("Bucket:     %s\n", $config['bucket']);
printf("Path style: %s\n", $pathStyle ? 'yes' : 'no');
printf("Compat:     %s\n\n", $compatibility ? 'yes' : 'no');

$client = new S3Client($options);

try {
    $result = $client->listObjectsV2([
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

if (getenv('S3_WRITE_TEST') === '1') {
    $testKey = 'smoketest/' . bin2hex(random_bytes(8)) . '.txt';
    $payload = 'fal-s3-driver smoke test ' . date('c');

    echo "\nWrite test:\n";

    try {
        $client->putObject([
            'Bucket' => $config['bucket'],
            'Key' => $testKey,
            'Body' => $payload,
            'ContentType' => 'text/plain',
        ]);
        printf("  put     %s\n", $testKey);

        $roundTrip = (string)$client->getObject([
            'Bucket' => $config['bucket'],
            'Key' => $testKey,
        ])['Body'];
        printf("  get     %s\n", $roundTrip === $payload ? 'content matches' : 'CONTENT MISMATCH');

        $client->deleteObject(['Bucket' => $config['bucket'], 'Key' => $testKey]);
        printf("  delete  done\n");
    } catch (AwsException $e) {
        fwrite(STDERR, sprintf(
            "\nWRITE FAILED\n  HTTP status: %s\n  AWS code:    %s\n  Message:     %s\n",
            $e->getStatusCode() ?? 'n/a',
            $e->getAwsErrorCode() ?? 'n/a',
            $e->getAwsErrorMessage() ?? $e->getMessage()
        ));
        exit(1);
    }
}

echo "\nOK\n";
