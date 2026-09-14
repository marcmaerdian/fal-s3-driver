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

$environment = require __DIR__ . '/environment.php';

if ($environment['config'] === null) {
    foreach ($environment['missing'] as $name) {
        fwrite(STDERR, sprintf("Missing environment variable: %s\n", $name));
    }
    exit(1);
}

$config = $environment['config'];

$options = [
    'version' => 'latest',
    'region' => $config['region'],
    'endpoint' => $config['endpoint'],
    'use_path_style_endpoint' => $config['usePathStyleEndpoint'],
    'credentials' => [
        'key' => $config['accessKeyId'],
        'secret' => $config['secretAccessKey'],
    ],
];

if ($config['compatibilityMode']) {
    $options['request_checksum_calculation'] = 'when_required';
    $options['response_checksum_validation'] = 'when_required';
}

printf("Environment: %s\n", $config['environment'] !== '' ? $config['environment'] : '(none)');
printf("Endpoint:   %s\n", $options['endpoint']);
printf("Region:     %s\n", $config['region']);
printf("Bucket:     %s\n", $config['bucket']);
printf("Path style: %s\n", $config['usePathStyleEndpoint'] ? 'yes' : 'no');
printf("Compat:     %s\n\n", $config['compatibilityMode'] ? 'yes' : 'no');

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
