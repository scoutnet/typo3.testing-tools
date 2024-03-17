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

namespace ScoutNet\TestingTools\Controller;

use GuzzleHttp\Psr7\Uri;

use function ini_get;

use JsonException;
use PHPUnit\Util\PHP\AbstractPhpProcess;

use Psr\Http\Message\ResponseInterface;
use ScoutNet\TestingTools\_abstractFunctionalTestCase;
use ScoutNet\TestingTools\Fixtures\TestMboxTransport;
use SebastianBergmann\Environment\Runtime;
use SebastianBergmann\Template\Template;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Exception;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalResponse;
use TYPO3\TestingFramework\Core\Testbase;

abstract class _abstractControllerFunctionalTestCase extends _abstractFunctionalTestCase
{
    // needs to be in sync with typoscript
    public const GROUP_VALID_USER_GROUP = 1;
    public const GROUP_EMAIL_NOT_VALIDATED_GROUP = 2;
    public const GROUP_USERDATA_NOT_ENTERED_GROUP = 3;
    public const GROUP_EMAIL_REVALIDATE_GROUP = 4;
    public const GROUP_SCOUTNET_ADMIN_GROUP = 5;

    public const USER_VALID_USER_GROUP = 1;
    public const USER_EMAIL_NOT_VALIDATED_GROUP = 2;
    public const USER_NO_USERDATA_GROUP = 3;
    public const USER_EMAIL_REVALIDATE_GROUP_AND_VALID_USER_GROUP = 4;
    public const USER_EMAIL_REVALIDATE_GROUP_AND_NO_USERDATA_GROUP = 5;

    public const PAGE_USER_CONTROLLER = 2;
    public const PAGE_USER_DATA = 3;
    public const PAGE_COMMUNITY = 11;
    public const PAGE_EDIT_PERMISSIONS = 12;
    public const PAGE_REQUEST_PERMISSIONS = 5;
    public const PAGE_ACCESS_RIGHTS = 6;

    public const PAGE_REGISTER = 7;
    public const PAGE_VALIDATE_EMAIL = 8;
    public const PAGE_NEW_USER_DATA = 9;
    public const PAGE_CONTACT_FORM = 10;

    public const NOT_SO_SECRET_ENCRYPTION_KEY = 'i-am-not-a-secure-encryption-key';

    public const STAMM_VALID_STAMM = 4;

    protected string $parameterPrefix;
    protected string $controller;
    protected int $pluginPid = self::PAGE_USER_CONTROLLER;
    protected string $pluginPageTitle = 'Controller';

    abstract protected static function getPluginSlug(): String;

    public static array $headers = [];

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
        // reset Headers
        self::$headers = [];

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

    /**
     * @param InternalRequest $request
     * @param InternalRequestContext $context
     * @param bool $withJsonResponse
     *
     * @return array
     * @throws JsonException
     */
    protected function retrieveFrontendRequestResult(InternalRequest $request, InternalRequestContext $context, bool $withJsonResponse = true): array
    {
        $arguments = [
            'withJsonResponse' => $withJsonResponse,
            'documentRoot' => $this->instancePath,
            'request' => json_encode($request, JSON_THROW_ON_ERROR),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ];

        if ($_SERVER['XDEBUG_CONFIG'] ?? '') {
            $arguments['xdebug_config'] = $_SERVER['XDEBUG_CONFIG'];
        }

        $vendorPath = (new Testbase())->getPackagesPath();
        $template = new Template($vendorPath . '/typo3/testing-framework/Resources/Core/Functional/Fixtures/Frontend/request.tpl');
        $template->setVar(
            [
                'arguments' => var_export($arguments, true),
                'documentRoot' => $this->instancePath,
                'originalRoot' => ORIGINAL_ROOT,
                'vendorPath' => $vendorPath . '/',
            ]
        );

        $settings = [];

        $php = AbstractPhpProcess::factory();
        $runtime = new Runtime();
        if ($runtime->hasXdebug()) {
            foreach (['remote_enable', 'remote_mode', 'remote_port', 'remote_host'] as $key) {
                $v = ini_get('xdebug.' . $key);
                if ($v !== false && trim($v) !== '') {
                    $settings['xdebug.' . $key] = 'xdebug.' . $key . '=' . $v;
                }
            }
            $settings = array_merge($settings, $settings);
        }
        return $php->runJob($template->render(), $settings);
    }

    protected function getRegexRedirectTo($pid): string
    {
        return '#<meta http-equiv="refresh" content="0;url=.*id=' . $pid . '"/>#';
    }

    abstract public static function dataProviderRedirect(): array;

    protected static function addQueryParameter(&$queryParameter, $parameter, $prefix): void
    {
        foreach ($parameter as $key => $value) {
            if (is_array($value)) {
                self::addQueryParameter($queryParameter, $value, $prefix . '[' . $key . ']');
            } else {
                $queryParameter[$prefix . '[' . $key . ']'] = $value;
            }
        }
    }

    /**
     * @param int $pid
     * @param array $parameters
     * @return string
     */
    protected static function generateCHash(int $pid, array $parameters): string
    {
        $cacheHashCalculator = GeneralUtility::makeInstance(CacheHashCalculator::class);

        $param = $parameters;
        $param['encryptionKey'] = self::NOT_SO_SECRET_ENCRYPTION_KEY;
        $param['id'] = $pid;

        // sort by key
        ksort($param);

        return $cacheHashCalculator->calculateCacheHash(array_map('strval', $param));
    }

    /**
     * @param string $url_part
     * @param array $queryParameters
     * @param string|null $body
     * @param int|null $user
     *
     * @return InternalResponse
     */
    protected function callFrontendUrl(string $url_part, array $queryParameters = [], ?string $body = null, ?int $user = null): ResponseInterface
    {
        // set encryptionKey for hmac to work
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NOT_SO_SECRET_ENCRYPTION_KEY;

        $req = (new InternalRequest())->withUri(new Uri('http://localhost' . $url_part), true)->withQueryParameters($queryParameters);

        // write html body
        if ($body !== null) {
            $req->getBody()->write($body);
        }

        $context = new InternalRequestContext();
        if ($user !== null) {
            $context = $context->withFrontendUserId($user);
        }

        $res = $this->executeFrontendSubRequest($req, $context);

        // Add Headers to response since typo3v11 is not filling those correctly
        foreach (self::$headers as $header) {
            if (strpos($header, ': ')) {
                [$key, $value] = explode(': ', $header, 2);
                $res = $res->withHeader($key, $value);
            } elseif (str_starts_with($header, 'HTTP/')) {
                $statusCode = explode(' ', $header)[1];
                $reason = explode(' ', $header, 3)[2];

                $res = $res->withStatus($statusCode, $reason);
            }
        }

        return $res;
    }

    /**
     * This test checks if the email validation token works
     *
     * @dataProvider dataProviderRedirect
     *
     * @param string   $action
     * @param array    $parameter
     * @param int|null $user
     * @param string   $redirect_to
     * @param array    $raw_parameter
     */
    public function testRedirect(?string $action = null, ?array $parameter = null, ?int $user = null, string $redirect_to = 'default', array $raw_parameter = []): void
    {
        if ($action === null) {
            print 'no Redirects defined';
            return;
        }

        self::$headers = [];

        // TODO: create possibility to use 'url:' as action
        $queryParameter = [
            'id' => $this->pluginPid,
            $this->parameterPrefix . '[action]' => $action,
            $this->parameterPrefix . '[controller]' => $this->controller,
        ];

        foreach ($parameter as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $queryParameter[$this->parameterPrefix . '[' . $key . '][' . $k . ']'] = $v;
                }
            } else {
                $queryParameter[$this->parameterPrefix . '[' . $key . ']'] = $value;
            }
        }
        foreach ($raw_parameter as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $queryParameter[$key . '[' . $k . ']'] = $v;
                }
            } else {
                $queryParameter[$key] = $value;
            }
        }

        $queryParameter['cHash'] = $this->generateCHash($this->pluginPid, $queryParameter);

        // Send Request with correct user
        $request = (new InternalRequest())->withQueryParameters($queryParameter);
        $context = new InternalRequestContext();
        if ($user !== null) {
            $context = $context->withFrontendUserId($user);
        }
        $response = $this->executeFrontendSubRequest($request, $context);

        $content = (string)$response->getBody();
        if ($redirect_to === '404') {
            self::assertContains('HTTP/1.1 404 Not Found', self::$headers);
        } elseif ($redirect_to === '403') {
            self::assertContains('HTTP/1.1 403 Forbidden', self::$headers);
        } else {
            self::assertMatchesRegularExpression('#<title>' . $this->pluginPageTitle . '</title>#', $content);
            self::assertContains('HTTP/1.1 303 See Other', self::$headers);

            // check location header
            $location_found = false;
            foreach (self::$headers as $header) {
                if (strlen($header) > 10 && str_starts_with($header, 'location: ')) {
                    $location_found = true;
                    if (str_starts_with($redirect_to, 'regex:')) {
                        self::assertMatchesRegularExpression('#^location: ' . substr($redirect_to, 6) . '#', $header);
                    } elseif (str_starts_with($redirect_to, 'url:')) {
                        self::assertEquals('location: ' . substr($redirect_to, 4), $header);
                    } else {
                        self::assertMatchesRegularExpression('#^location: (http|https)://localhost/' . ltrim(static::getPluginSlug(), '/') . '[?]' . $this->parameterPrefix . '%5Baction%5D=' . $redirect_to . '&' . $this->parameterPrefix . '%5Bcontroller%5D=' . $this->controller . '&.*#', $header);
                    }
                }
            }

            // check that location header is present
            self::assertTrue($location_found, 'location Header not found!!');
        }
    }
}

// Mock header send so we get the headers from redirect controller

namespace TYPO3\CMS\Extbase\Core;

use ScoutNet\TestingTools\Controller\_abstractControllerFunctionalTestCase;

function headers_sent(): bool
{
    return false;
}

function header($header)
{
    _abstractControllerFunctionalTestCase::$headers[] = $header;
}
