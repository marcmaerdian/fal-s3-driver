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
        // A variable already set in the shell wins over the file, so a single
        // run can be overridden without editing anything.
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

$environment = strtoupper(trim((string)(getenv('S3_ENV') ?: '')));
$suffix = $environment !== '' ? '_' . $environment : '';

$read = static function (string $name) use ($suffix): string {
    return trim((string)(getenv($name . $suffix) ?: ''));
};

$missing = [];
foreach (['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'] as $name) {
    if ($read($name) === '') {
        $missing[] = $name . $suffix;
    }
}

// Reporting is left to the caller: a CLI script wants to abort with a message,
// a test wants to skip itself. Exiting here would kill the test runner.
if ($missing !== []) {
    return ['config' => null, 'missing' => $missing];
}

// The config keys deliberately match the FlexForm field names, so the array can
// be handed to the driver as its configuration without any translation.
return ['missing' => [], 'config' => [
    'environment' => $environment,
    'endpoint' => rtrim($read('S3_ENDPOINT'), '/'),
    'region' => $read('S3_REGION') ?: 'auto',
    'bucket' => $read('S3_BUCKET'),
    'accessKeyId' => $read('S3_ACCESS_KEY_ID'),
    'secretAccessKey' => $read('S3_SECRET_ACCESS_KEY'),
    'publicBaseUrl' => $read('S3_PUBLIC_BASE_URL'),
    'basePath' => $read('S3_BASE_PATH'),
    'usePathStyleEndpoint' => $read('S3_PATH_STYLE') !== '0',
    'compatibilityMode' => $read('S3_COMPATIBILITY') !== '0',
]];
