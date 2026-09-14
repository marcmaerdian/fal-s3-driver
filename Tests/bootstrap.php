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

use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

// GeneralUtility::tempnam() resolves its target through Environment, which is
// normally set up while TYPO3 boots. Outside of TYPO3 we do it ourselves.
$projectPath = dirname(__DIR__);

Environment::initialize(
    new ApplicationContext('Testing'),
    true,
    true,
    $projectPath,
    $projectPath . '/public',
    $projectPath . '/var',
    $projectPath . '/config',
    __FILE__,
    'UNIX'
);
