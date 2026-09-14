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

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['R2'] = [
    'class' => \Marcmaerdian\FalR2Driver\Driver\R2Driver::class,
    'shortName' => 'R2',
    'label' => 'Cloudflare R2',
    'flexFormDS' => 'FILE:EXT:fal_r2_driver/Configuration/FlexForm/R2DriverFlexForm.xml',
];
