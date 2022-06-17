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

namespace ScoutNet\TestingTools\ViewHelpers;

use ScoutNet\TestingTools\_abstractFunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\TestingFramework\Core\Exception;

abstract class _abstractViewHelperFunctionalTestCase extends _abstractFunctionalTestCase
{
    protected string $viewHelper = '';
    protected string $subViewTemplate = '';

    protected $coreExtensionsToLoad = [
        'extensionmanager', 'fluid_styled_content', 'frontend',
    ];

    /**
     * @var array Have extensions loaded
     */
    protected $testExtensionsToLoad = [
        'typo3conf/ext/scoutnet_structure',
        'typo3conf/ext/static_info_tables',
        'typo3conf/ext/scoutnet_community',
    ];

    /**
     * @var string[]
     */
    protected $additionalFoldersToCreate = [
        'typo3conf/sites/test_site',
    ];

    /**
     * @var string[]
     */
    protected $pathsToLinkInTestInstance = [
        'typo3conf/ext/scoutnet_community/Tests/Functional/Fixtures/Frontend/Config/Sites/test_site/config.yaml' => 'typo3conf/sites/test_site/config.yaml', // so correct urls
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withDatabaseSnapshot(/**
         * @throws Exception
         */ function () {
            $this->importDataSet('EXT:scoutnet_community/Tests/Functional/Fixtures/DatabaseRecords/Base.xml');
            $this->importDataSet('EXT:scoutnet_community/Tests/Functional/Fixtures/DatabaseRecords/ContactController.xml');
            $this->setUpFrontendRootPage(
                1,
                [
                    'constants' => ['EXT:scoutnet_community/Tests/Functional/Fixtures/TypoScript/_BaseController.constants.typoscript'],
                    'setup' => ['EXT:scoutnet_community/Tests/Functional/Fixtures/TypoScript/_BaseController.setup.typoscript'],
                ],
                [
                    'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
                ]
            );
        });
    }

    /**
     * @return array
     */
    abstract public function dataProviderInvalidArguments(): array;

    /**
     * @param array $arguments
     * @param       $error
     *
     * @dataProvider dataProviderInvalidArguments
     */
    public function testInvalidArguments(array $arguments, $error): void
    {
        $this->expectException($error[0]);
        $this->expectExceptionCode($error[1]);

        GeneralUtility::setIndpEnv('TYPO3_REQUEST_URL', 'localhost');
        $view = new StandaloneView();
        $view->assignMultiple($arguments);

        $variables = implode(' ', array_map(static function ($k) {return $k . '="{' . $k . '}"';}, array_keys($arguments)));
        $view->setTemplateSource('<' . $this->viewHelper . ' ' . $variables . ' />');

        $view->render();
    }

    /**
     * @return array
     */
    abstract public function dataProviderValidArguments(): array;

    /**
     * @param array $arguments
     * @param       $expected
     *
     * @dataProvider dataProviderValidArguments
     */
    public function testValidArguments(array $arguments, $expected): void
    {
        GeneralUtility::setIndpEnv('TYPO3_REQUEST_URL', 'localhost');
        $view = new StandaloneView();
        $view->assignMultiple($arguments);

        $variables = implode(' ', array_map(static function ($k) {return $k . '="{' . $k . '}"';}, array_keys($arguments)));

        $view->setTemplateSource('<' . $this->viewHelper . ' ' . $variables . ' >' . $this->subViewTemplate . '</' . $this->viewHelper . '>');
        self::assertSame($expected, $view->render());
    }
}
