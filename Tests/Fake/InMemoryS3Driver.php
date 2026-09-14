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

namespace MARCMAERDIAN\FalS3Driver\Tests\Fake;

use MARCMAERDIAN\FalS3Driver\Driver\S3Driver;
use MARCMAERDIAN\FalS3Driver\Service\ObjectRepository;

/**
 * The real driver, pointed at an in-memory store.
 *
 * Only createObjectRepository() is overridden, so every line of logic under
 * test is the production one.
 */
final class InMemoryS3Driver extends S3Driver
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration, private readonly ObjectRepository $repository)
    {
        parent::__construct($configuration);
    }

    protected function createObjectRepository(): ObjectRepository
    {
        return $this->repository;
    }
}
