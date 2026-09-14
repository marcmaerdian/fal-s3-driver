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

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\MARCMAERDIAN\FalS3Driver\Driver\S3Driver::DRIVER_TYPE] = [
    'class' => \MARCMAERDIAN\FalS3Driver\Driver\S3Driver::class,
    'shortName' => \MARCMAERDIAN\FalS3Driver\Driver\S3Driver::DRIVER_TYPE,
    'label' => 'S3-compatible storage (R2, Hetzner, MinIO, AWS, R2 Cloudflare)',
    'flexFormDS' => 'FILE:EXT:' . \MARCMAERDIAN\FalS3Driver\Driver\S3Driver::EXTENSION_KEY
        . '/Configuration/FlexForm/S3DriverFlexForm.xml',
];
