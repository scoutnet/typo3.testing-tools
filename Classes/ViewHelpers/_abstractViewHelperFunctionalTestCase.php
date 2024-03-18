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

use Psr\Http\Message\ServerRequestInterface;
use ScoutNet\ScoutnetStructure\ViewHelpers\Structure\SelectViewHelper;
use ScoutNet\TestingTools\_abstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Fluid\View\StandaloneView;

abstract class _abstractViewHelperFunctionalTestCase extends _abstractFunctionalTestCase
{
    protected string $viewHelper = '';
    protected string $subViewTemplate = '';

    protected array $coreExtensionsToLoad = [
        'extensionmanager', 'fluid_styled_content', 'frontend',
    ];

    /**
     * @var array Have extensions loaded
     */
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/scoutnet_structure',
        'typo3conf/ext/static_info_tables',
        'typo3conf/ext/scoutnet_community',
    ];

    /**
     * @var string[]
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/scoutnet_community/Tests/Functional/Fixtures/Frontend/Config/Sites' => 'typo3conf/sites', // so correct urls
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withDatabaseSnapshot(function () {
            $this->setupDatabase();
        });
    }

    abstract protected function setupDatabase(): void;

    /**
     * @return array
     */
    abstract public static function dataProviderInvalidArguments(): array;

    /**
     * @param array $arguments
     * @param       $error
     *
     * @dataProvider dataProviderInvalidArguments
     */
    public function testInvalidArguments(?array $arguments = null, $error = null): void
    {
        if ($arguments === null) {
            // no invalid Arguments specified
            return;
        }

        $this->expectException($error[0]);
        $this->expectExceptionCode($error[1]);

        GeneralUtility::setIndpEnv('TYPO3_REQUEST_URL', 'localhost');
        $view = new StandaloneView();
        $view->assignMultiple($arguments);
        $view->setRequest(new Request($this->createServerRequest('http://localhost/')));

        $variables = implode(' ', array_map(static function ($k) {return $k . '="{' . $k . '}"';}, array_keys($arguments)));
        $view->setTemplateSource('<' . $this->viewHelper . ' ' . $variables . ' />');

        $view->render();
    }

    /**
     * @return array
     */
    abstract public static function dataProviderValidArguments(): array;

    /**
     * @param array $arguments
     * @param       $expected
     *
     * @dataProvider dataProviderValidArguments
     */
    public function testValidArguments(?array $arguments = null, $expected = null): void
    {
        if ($arguments === null) {
            // no valid Arguments specified
            return;
        }

        GeneralUtility::setIndpEnv('TYPO3_REQUEST_URL', 'localhost');
        $view = new StandaloneView();
        $view->assignMultiple($arguments);
        $view->setRequest(new Request($this->createServerRequest('http://localhost/')));

        $variables = implode(' ', array_map(static function ($k) {return $k . '="{' . $k . '}"';}, array_keys($arguments)));

        $view->setTemplateSource('<' . $this->viewHelper . ' ' . $variables . ' >' . $this->subViewTemplate . '</' . $this->viewHelper . '>');
        self::assertSame($expected, $view->render());
    }

    protected function createServerRequest(string $url, string $method = 'GET'): ServerRequestInterface
    {
        $requestUrlParts = parse_url($url);
        $docRoot = $this->instancePath;

        // @todo: Remove when dropping support for v12
        $hasConsolidatedHttpEntryPoint = class_exists(CoreHttpApplication::class);
        $scriptPrefix = $hasConsolidatedHttpEntryPoint ? '' : '/typo3';

        $serverParams = [
            'DOCUMENT_ROOT' => $docRoot,
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
            'HTTP_HOST' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_NAME' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => $scriptPrefix . '/index.php',
            'PHP_SELF' => $scriptPrefix . '/index.php',
            'SCRIPT_FILENAME' => $docRoot . '/index.php',
            'PATH_TRANSLATED' => $docRoot . '/index.php',
            'QUERY_STRING' => $requestUrlParts['query'] ?? '',
            'REQUEST_URI' => $requestUrlParts['path'] . (isset($requestUrlParts['query']) ? '?' . $requestUrlParts['query'] : ''),
            'REQUEST_METHOD' => $method,
        ];
        // Define HTTPS and server port
        if (isset($requestUrlParts['scheme'])) {
            if ($requestUrlParts['scheme'] === 'https') {
                $serverParams['HTTPS'] = 'on';
                $serverParams['SERVER_PORT'] = '443';
            } else {
                $serverParams['SERVER_PORT'] = '80';
            }
        }

        // Define a port if used in the URL
        if (isset($requestUrlParts['port'])) {
            $serverParams['SERVER_PORT'] = $requestUrlParts['port'];
        }
        // set up normalizedParams
        $request = new ServerRequest($url, $method, null, [], $serverParams);

        $request = $request
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('extbase', new ExtbaseRequestParameters(SelectViewHelper::class))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        return $request;
    }
}
