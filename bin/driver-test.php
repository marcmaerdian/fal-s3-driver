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
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

require __DIR__ . '/../vendor/autoload.php';

// GeneralUtility::tempnam() resolves its target through Environment, which is
// normally set up while TYPO3 boots. Outside of TYPO3 we do it ourselves.
$projectPath = dirname(__DIR__);
Environment::initialize(
    new ApplicationContext('Development'),
    true,
    true,
    $projectPath,
    $projectPath . '/public',
    $projectPath . '/var',
    $projectPath . '/config',
    __FILE__,
    'UNIX'
);

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
        'hash(default is identifier hash)' => [
            $driver->hash($root . '/readme.txt', 'md5'),
            sha1($root . '/readme.txt'),
        ],
    ];

    // --- writing -----------------------------------------------------------
    $writeRoot = $root . '/write/';
    $created = $driver->createFolder('write', $root . '/');
    $assertions['createFolder returns identifier'] = [$created, $writeRoot];
    $assertions['createFolder -> folderExists'] = [$driver->folderExists($writeRoot), true];
    $assertions['new folder is empty'] = [$driver->isFolderEmpty($writeRoot), true];

    $newFile = $driver->createFile('note.txt', $writeRoot);
    $assertions['createFile returns identifier'] = [$newFile, $writeRoot . 'note.txt'];
    $assertions['setFileContents returns length'] = [$driver->setFileContents($newFile, 'written by the driver'), 21];
    $assertions['read back written content'] = [$driver->getFileContents($newFile), 'written by the driver'];
    $assertions['folder no longer empty'] = [$driver->isFolderEmpty($writeRoot), false];

    $copied = $driver->copyFileWithinStorage($newFile, $writeRoot, 'copy.txt');
    $assertions['copyFileWithinStorage'] = [$driver->getFileContents($copied), 'written by the driver'];

    $renamed = $driver->renameFile($copied, 'renamed.txt');
    $assertions['renameFile returns identifier'] = [$renamed, $writeRoot . 'renamed.txt'];
    $assertions['renameFile removed source'] = [$driver->fileExists($copied), false];

    $localFile = tempnam(sys_get_temp_dir(), 'fals3') . '.txt';
    file_put_contents($localFile, 'uploaded from disk');
    $added = $driver->addFile($localFile, $writeRoot, 'uploaded.txt');
    $assertions['addFile'] = [$driver->getFileContents($added), 'uploaded from disk'];
    $assertions['addFile removed original'] = [file_exists($localFile), false];

    $assertions['sanitizeFileName'] = [$driver->sanitizeFileName('Bild mit Leerzeichen.PNG'), 'Bild_mit_Leerzeichen.PNG'];
    $assertions['mimetype of uploaded'] = [$driver->getFileInfoByIdentifier($added)['mimetype'], 'text/plain'];

    $moved = $driver->moveFolderWithinStorage($writeRoot, $root . '/', 'moved');
    $assertions['moveFolderWithinStorage mapping'] = [count($moved), 3];
    $assertions['moveFolder removed source'] = [$driver->folderExists($writeRoot), false];
    $assertions['moveFolder created target'] = [$driver->folderExists($root . '/moved/'), true];

    $assertions['deleteFile'] = [$driver->deleteFile($root . '/moved/note.txt'), true];
    $assertions['deleteFile worked'] = [$driver->fileExists($root . '/moved/note.txt'), false];
    $assertions['deleteFolder recursive'] = [$driver->deleteFolder($root . '/moved/', true), true];
    $assertions['deleteFolder worked'] = [$driver->folderExists($root . '/moved/'), false];

    // --- local processing and streaming ------------------------------------
    $localCopy = $driver->getFileForLocalProcessing($root . '/readme.txt', false);
    $assertions['getFileForLocalProcessing'] = [is_readable($localCopy), true];
    $assertions['local copy has content'] = [file_get_contents($localCopy), 'hello from the driver test'];
    $assertions['local copy keeps extension'] = [pathinfo($localCopy, PATHINFO_EXTENSION), 'txt'];
    $assertions['read-only copy is reused'] = [
        $driver->getFileForLocalProcessing($root . '/readme.txt', false),
        $localCopy,
    ];
    $assertions['writable copy is fresh'] = [
        $driver->getFileForLocalProcessing($root . '/readme.txt', true) !== $localCopy,
        true,
    ];

    ob_start();
    $driver->dumpFileContents($root . '/readme.txt');
    $assertions['dumpFileContents'] = [ob_get_clean(), 'hello from the driver test'];

    // --- fixes verified against the reference implementation ---------------
    $hashingDriver = new S3Driver($config + ['useContentHash' => true]);
    $hashingDriver->processConfiguration();
    $hashingDriver->initialize();
    $assertions['content hash when enabled'] = [
        $hashingDriver->hash($root . '/readme.txt', 'md5'),
        md5('hello from the driver test'),
    ];

    $nested = $driver->createFolder('jahr/2026/bilder', $root . '/', true);
    $assertions['createFolder recursive keeps depth'] = [$nested, $root . '/jahr/2026/bilder/'];
    $assertions['recursive folder exists'] = [$driver->folderExists($nested), true];
    $driver->deleteFolder($root . '/jahr/', true);

    $flat = $driver->createFolder('jahr/2026', $root . '/');
    $assertions['createFolder flat sanitises slash'] = [$flat, $root . '/jahr_2026/'];
    $driver->deleteFolder($flat, true);

    // --- metadata cache -----------------------------------------------------
    $counting = new S3Driver($config);
    $counting->processConfiguration();
    $counting->initialize();

    // Reach into the protected client instead of adding a test-only accessor
    // to the driver's public API.
    $clientProperty = new ReflectionProperty(S3Driver::class, 'client');
    $countingClient = $clientProperty->getValue($counting);

    $requests = 0;
    $countingClient->getHandlerList()->appendSign(
        \Aws\Middleware::tap(static function ($command) use (&$requests): void {
            if ($command->getName() === 'HeadObject') {
                $requests++;
            }
        }),
        'count-head'
    );

    $files = $counting->getFilesInFolder($root . '/images/');
    foreach ($files as $identifier) {
        $counting->getFileInfoByIdentifier($identifier);
    }
    $assertions['listing serves file info without HeadObject'] = [$requests, 0];
    $assertions['cached size is correct'] = [
        $counting->getFileInfoByIdentifier($root . '/images/icon.svg')['size'],
        11,
    ];

    // Without a listing the first lookup must still fall back to HeadObject.
    $cold = new S3Driver($config);
    $cold->processConfiguration();
    $cold->initialize();
    $assertions['cold lookup still works'] = [
        $cold->getFileInfoByIdentifier($root . '/readme.txt')['size'],
        26,
    ];

    // --- cache control ------------------------------------------------------
    $cached = new S3Driver($config + ['cacheControlMaxAge' => 604800]);
    $cached->processConfiguration();
    $cached->initialize();
    $cached->setFileContents($root . '/cached.txt', 'with cache control');
    $header = $client->headObject([
        'Bucket' => $config['bucket'],
        'Key' => $prefix . '/cached.txt',
    ]);
    $assertions['CacheControl reaches the object'] = [
        (string)($header['CacheControl'] ?? ''),
        'max-age=604800',
    ];

    $plain = $driver->createFile('plain.txt', $root . '/');
    $driver->setFileContents($plain, 'no cache control');
    $headerPlain = $client->headObject(['Bucket' => $config['bucket'], 'Key' => $prefix . '/plain.txt']);
    $assertions['no header when max-age is 0'] = [
        (string)($headerPlain['CacheControl'] ?? ''),
        '',
    ];

    // A write must invalidate what the listing cached.
    $counting->setFileContents($root . '/images/icon.svg', 'changed');
    $assertions['write invalidates cache'] = [
        $counting->getFileInfoByIdentifier($root . '/images/icon.svg')['size'],
        7,
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
    // Remove everything below the test prefix, not just the seeded keys: the
    // write tests create objects of their own.
    $leftovers = [];
    foreach ($client->getPaginator('ListObjectsV2', ['Bucket' => $config['bucket'], 'Prefix' => $prefix]) as $page) {
        foreach ($page['Contents'] ?? [] as $object) {
            $leftovers[] = ['Key' => (string)$object['Key']];
        }
    }
    if ($leftovers !== []) {
        $client->deleteObjects(['Bucket' => $config['bucket'], 'Delete' => ['Objects' => $leftovers]]);
    }
    printf("\n  cleaned up %d object(s)\n", count($leftovers));
}

printf("\n%s\n", $failed === 0 ? 'ALL PASSED' : $failed . ' FAILED');
exit($failed === 0 ? 0 : 1);
