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

use Aws\S3\S3Client;
use Marcmaerdian\FalS3Driver\Driver\S3Driver;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/environment.php';

$driver = new S3Driver($config);
$driver->processConfiguration();
$driver->initialize();

// Seed a small tree through the SDK directly, because writing through the
// driver is not implemented yet.
$client = new S3Client([
    'version' => 'latest',
    'region' => $config['region'],
    'endpoint' => $config['endpoint'],
    'use_path_style_endpoint' => $config['usePathStyleEndpoint'],
    'credentials' => ['key' => $config['accessKeyId'], 'secret' => $config['secretAccessKey']],
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
]);

$run = bin2hex(random_bytes(4));
$basePrefix = $config['basePath'] !== '' ? $config['basePath'] . '/' : '';
$prefix = $basePrefix . 'drivertest-' . $run;

$seeded = [
    $prefix . '/readme.txt' => 'hello from the driver test',
    $prefix . '/images/logo.png' => 'not really a png',
    $prefix . '/images/icon.svg' => '<svg></svg>',
    $prefix . '/docs/deep/manual.txt' => 'deeply nested',
];

printf("Environment: %s\n", $config['environment'] !== '' ? $config['environment'] : '(none)');
printf("Bucket:      %s\n", $config['bucket']);
printf("Test prefix: %s\n\n", $prefix);

foreach ($seeded as $key => $body) {
    $client->putObject(['Bucket' => $config['bucket'], 'Key' => $key, 'Body' => $body]);
}

$root = '/drivertest-' . $run;

try {
    $assertions = [
        'folderExists(root)' => [$driver->folderExists('/'), true],
        'folderExists(test folder)' => [$driver->folderExists($root . '/'), true],
        'folderExists(sub folder)' => [$driver->folderExists($root . '/images/'), true],
        'folderExists(missing)' => [$driver->folderExists('/nope-' . $run . '/'), false],
        'fileExists(readme)' => [$driver->fileExists($root . '/readme.txt'), true],
        'fileExists(nested)' => [$driver->fileExists($root . '/images/logo.png'), true],
        'fileExists(missing)' => [$driver->fileExists($root . '/nope.txt'), false],
        'fileExists(folder identifier)' => [$driver->fileExists($root . '/'), false],
        'fileExistsInFolder' => [$driver->fileExistsInFolder('readme.txt', $root . '/'), true],
        'folderExistsInFolder' => [$driver->folderExistsInFolder('images', $root . '/'), true],
        'isFolderEmpty(seeded)' => [$driver->isFolderEmpty($root . '/'), false],
        'getFileInFolder' => [$driver->getFileInFolder('readme.txt', $root . '/'), $root . '/readme.txt'],
        'getFolderInFolder' => [$driver->getFolderInFolder('images', $root . '/'), $root . '/images/'],
        'getFileContents' => [$driver->getFileContents($root . '/readme.txt'), 'hello from the driver test'],
        'getRootLevelFolder' => [$driver->getRootLevelFolder(), '/'],
        'isWithin(match)' => [$driver->isWithin($root . '/', $root . '/readme.txt'), true],

        // Listing: one level only
        'getFilesInFolder(root)' => [
            array_values($driver->getFilesInFolder($root . '/')),
            [$root . '/readme.txt'],
        ],
        'getFoldersInFolder(root)' => [
            array_values($driver->getFoldersInFolder($root . '/')),
            [$root . '/docs/', $root . '/images/'],
        ],
        'getFilesInFolder(images)' => [
            array_values($driver->getFilesInFolder($root . '/images/')),
            [$root . '/images/icon.svg', $root . '/images/logo.png'],
        ],

        // Listing: recursive
        'countFilesInFolder(recursive)' => [$driver->countFilesInFolder($root . '/', true), 4],
        'countFilesInFolder(flat)' => [$driver->countFilesInFolder($root . '/'), 1],
        'countFoldersInFolder(flat)' => [$driver->countFoldersInFolder($root . '/'), 2],
        'countFoldersInFolder(recursive)' => [$driver->countFoldersInFolder($root . '/', true), 3],

        // Paging
        'getFilesInFolder(limit 1)' => [
            count($driver->getFilesInFolder($root . '/', 0, 1, true)),
            1,
        ],

        // File info
        'fileInfo size' => [$driver->getFileInfoByIdentifier($root . '/readme.txt')['size'], 26],
        'fileInfo name' => [$driver->getFileInfoByIdentifier($root . '/readme.txt')['name'], 'readme.txt'],
        'fileInfo extension' => [$driver->getFileInfoByIdentifier($root . '/readme.txt')['extension'], 'txt'],
        'fileInfo subset' => [
            array_keys($driver->getFileInfoByIdentifier($root . '/readme.txt', ['size', 'name'])),
            ['size', 'name'],
        ],
        'folderInfo name' => [$driver->getFolderInfoByIdentifier($root . '/images/')['name'], 'images'],
        'hash(md5)' => [$driver->hash($root . '/readme.txt', 'md5'), md5('hello from the driver test')],
    ];

    $failed = 0;
    foreach ($assertions as $label => [$actual, $expected]) {
        $ok = $actual === $expected;
        $failed += $ok ? 0 : 1;
        printf(
            "  %-32s %-5s %s\n",
            $label,
            $ok ? 'OK' : 'FAIL',
            $ok ? '' : sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
        );
    }

    printf("\n  getPublicUrl: %s\n", var_export($driver->getPublicUrl($root . '/readme.txt'), true));
} finally {
    foreach (array_keys($seeded) as $key) {
        $client->deleteObject(['Bucket' => $config['bucket'], 'Key' => $key]);
    }
}

printf("\n%s\n", $failed === 0 ? 'ALL PASSED' : $failed . ' FAILED');
exit($failed === 0 ? 0 : 1);
