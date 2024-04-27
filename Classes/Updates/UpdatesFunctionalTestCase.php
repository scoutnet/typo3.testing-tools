<?php
/**
 ************************************************************************
 * Copyright (c) 2005-2019 Stefan (Mütze) Horst                        *
 ************************************************************************
 * I don't have the time to read through all the licences to find out   *
 * what they exactly say. But it's simple. It's free for non-commercial *
 * projects, but as soon as you make money with it, i want my share :-) *
 * (License : Free for non-commercial use)                              *
 ************************************************************************
 * Authors: Stefan (Mütze) Horst <muetze@scoutnet.de>               *
 ************************************************************************
 */

namespace ScoutNet\TestingTools\Command;

use ScoutNet\TestingTools\_abstractFunctionalTestCase;
use ScoutNet\TestingTools\Fixtures\TestMboxTransport;
use TYPO3\TestingFramework\Core\Exception;

abstract class UpdatesFunctionalTestCase extends _abstractFunctionalTestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'FE' => [
            'lockIP' => 0,
        ],
        'MAIL' => [
            'transport' => TestMboxTransport::class,
            'transport_mbox_file' => '',
        ],
    ];

    protected string $mboxPath = '/typo3temp/test.mbox';

    protected function setUp(): void
    {
        // setup mbox file before the parent sets up the localconf.php
        $this->mboxPath = self::getInstancePath() . '/typo3temp/test.mbox';
        if (is_string($this->configurationToUseInTestInstance)) {
            $this->configurationToUseInTestInstance = [
                'FE' => [
                    'lockIP' => 0,
                ],
                'MAIL' => [
                    'transport' => TestMboxTransport::class,
                    'transport_mbox_file' => '',
                ],
            ];
        }
        $this->configurationToUseInTestInstance['MAIL']['transport_mbox_file'] = $this->mboxPath;
        parent::setUp();

        $this->withDatabaseSnapshot(/**
         * @throws Exception
         */ function () {
            $this->setupDatabase();
        });
    }

    abstract protected function setupDatabase(): void;
}
