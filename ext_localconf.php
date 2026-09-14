<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['R2'] = [
    'class' => \Marcmaerdian\FalR2Driver\Driver\R2Driver::class,
    'shortName' => 'R2',
    'label' => 'Cloudflare R2',
    'flexFormDS' => 'FILE:EXT:fal_r2_driver/Configuration/FlexForm/R2DriverFlexForm.xml',
];