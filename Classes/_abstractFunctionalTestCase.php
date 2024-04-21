<?php
/**
 ************************************************************************
 * Copyright (c) 2005-2022 Stefan (Mütze) Horst                        *
 ************************************************************************
 * I don't have the time to read through all the licences to find out   *
 * what they exactly say. But it's simple. It's free for non-commercial *
 * projects, but as soon as you make money with it, i want my share :-) *
 * (License : Free for non-commercial use)                              *
 ************************************************************************
 * Authors: Stefan (Mütze) Horst <muetze@scoutnet.de>               *
 ************************************************************************
 */

namespace ScoutNet\TestingTools;

use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\BaseTestCase;
use TYPO3\TestingFramework\Core\Exception;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Snapshot\DatabaseAccessor;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Snapshot\DatabaseSnapshot;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\TestingFramework\Core\Testbase;

abstract class _abstractFunctionalTestCase extends FunctionalTestCase
{
    protected bool $isFirstTest = true;
    protected static string $currentTestCaseClass = '';

    // needs to be copied over, since private in FunctionalTestCase
    protected ContainerInterface $container;

    /**
     * Set up creates a test instance and database.
     *
     * This method should be called with parent::setUp() in your test cases!
     *
     * Custom Changed function which includes caching of dependencies injected code.
     *
     * @throws Exception
     */
    // TODO: this is a copy to cache the DependencyInjectionCache
    protected function setUp(): void
    {
        if (!defined('ORIGINAL_ROOT')) {
            self::markTestSkipped('Functional tests must be called through phpunit on CLI');
        }

        $this->identifier = self::getInstanceIdentifier();
        $this->instancePath = self::getInstancePath();
        putenv('TYPO3_PATH_ROOT=' . $this->instancePath);
        putenv('TYPO3_PATH_APP=' . $this->instancePath);

        $testbase = new Testbase();
        $testbase->setTypo3TestingContext();

        // See if we're the first test of this test case.
        $currentTestCaseClass = static::class;
        if (self::$currentTestCaseClass !== $currentTestCaseClass) {
            self::$currentTestCaseClass = $currentTestCaseClass;
        } else {
            $this->isFirstTest = false;
        }

        // sqlite db path preparation
        $dbPathSqlite = dirname($this->instancePath) . '/functional-sqlite-dbs/test_' . $this->identifier . '.sqlite';
        $dbPathSqliteEmpty = dirname($this->instancePath) . '/functional-sqlite-dbs/test_' . $this->identifier . '.empty.sqlite';

        if (!$this->isFirstTest) {
            // Reusing an existing instance. This typically happens for the second, third, ... test
            // in a test case, so environment is set up only once per test case.
            GeneralUtility::purgeInstances();
            $this->container = $testbase->setUpBasicTypo3Bootstrap($this->instancePath);
            if ($this->initializeDatabase) {
                $testbase->initializeTestDatabaseAndTruncateTables($dbPathSqlite, $dbPathSqliteEmpty);
            }
            $testbase->loadExtensionTables();
        } else {
            DatabaseSnapshot::initialize(dirname($this->getInstancePath()) . '/functional-sqlite-dbs/', $this->identifier);
            $testbase->removeOldInstanceIfExists($this->instancePath);
            // Basic instance directory structure
            $testbase->createDirectory($this->instancePath . '/fileadmin');
            $testbase->createDirectory($this->instancePath . '/typo3temp/var/transient');
            $testbase->createDirectory($this->instancePath . '/typo3temp/assets');
            $testbase->createDirectory($this->instancePath . '/typo3conf/ext');
            // Additionally requested directories
            foreach ($this->additionalFoldersToCreate as $directory) {
                $testbase->createDirectory($this->instancePath . '/' . $directory);
            }
            $defaultCoreExtensionsToLoad = [
                'core',
                'backend',
                'frontend',
                'extbase',
                'fluid',
            ];
            if ((new Typo3Version())->getMajorVersion() < 13) {
                // @todo: Remove with next major TF version
                $defaultCoreExtensionsToLoad[] = 'install';
            }
            $frameworkExtension = [
                'Resources/Core/Functional/Extensions/json_response',
                'Resources/Core/Functional/Extensions/private_container',
            ];
            $testbase->setUpInstanceCoreLinks($this->instancePath, $defaultCoreExtensionsToLoad, $this->coreExtensionsToLoad);
            $testbase->linkTestExtensionsToInstance($this->instancePath, $this->testExtensionsToLoad);
            $testbase->linkFrameworkExtensionsToInstance($this->instancePath, $frameworkExtension);
            $testbase->linkPathsInTestInstance($this->instancePath, $this->pathsToLinkInTestInstance);
            $testbase->providePathsInTestInstance($this->instancePath, $this->pathsToProvideInTestInstance);
            $localConfiguration['DB'] = $testbase->getOriginalDatabaseSettingsFromEnvironmentOrLocalConfiguration();

            $originalDatabaseName = '';
            $dbName = '';
            $dbDriver = $localConfiguration['DB']['Connections']['Default']['driver'];
            if ($dbDriver !== 'pdo_sqlite') {
                $originalDatabaseName = $localConfiguration['DB']['Connections']['Default']['dbname'];
                if ($originalDatabaseName !== preg_replace('/[^a-zA-Z0-9_]/', '', $originalDatabaseName)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Database name "%s" is invalid. Use a valid name, for example "%s".',
                            $originalDatabaseName,
                            preg_replace('/[^a-zA-Z0-9_]/', '', $originalDatabaseName)
                        ),
                        1695139917
                    );
                }
                // Append the unique identifier to the base database name to end up with a single database per test case
                $dbName = $originalDatabaseName . '_ft' . $this->identifier;
                $localConfiguration['DB']['Connections']['Default']['dbname'] = $dbName;
                $testbase->testDatabaseNameIsNotTooLong($originalDatabaseName, $localConfiguration);
                if ($dbDriver === 'mysqli' || $dbDriver === 'pdo_mysql') {
                    $localConfiguration['DB']['Connections']['Default']['charset'] = 'utf8mb4';
                    $localConfiguration['DB']['Connections']['Default']['tableoptions']['charset'] = 'utf8mb4';
                    $localConfiguration['DB']['Connections']['Default']['tableoptions']['collate'] = 'utf8mb4_unicode_ci';
                    $localConfiguration['DB']['Connections']['Default']['initCommands'] = 'SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY\';';
                }
            } else {
                // sqlite dbs of all tests are stored in a dir parallel to instance roots. Allows defining this path as tmpfs.
                $testbase->createDirectory(dirname($this->instancePath) . '/functional-sqlite-dbs');
                $localConfiguration['DB']['Connections']['Default']['path'] = $dbPathSqlite;
            }

            // Set some hard coded base settings for the instance. Those could be overruled by
            // $this->configurationToUseInTestInstance if needed again.
            $localConfiguration['SYS']['displayErrors'] = '1';
            $localConfiguration['SYS']['debugExceptionHandler'] = '';
            // By setting errorHandler to empty string, only the phpunit error handler is
            // registered in functional tests, so settings like convertWarningsToExceptions="true"
            // in FunctionalTests.xml will let tests fail that throw warnings.
            $localConfiguration['SYS']['errorHandler'] = '';
            $localConfiguration['SYS']['trustedHostsPattern'] = '.*';
            $localConfiguration['SYS']['encryptionKey'] = 'i-am-not-a-secure-encryption-key';
            $localConfiguration['GFX']['processor'] = 'GraphicsMagick';
            // Set cache backends to null backend instead of database backend let us save time for creating
            // database schema for it and reduces selects/inserts to the database for cache operations, which
            // are generally not really needed for functional tests. Specific tests may restore this in if needed.
            $localConfiguration['SYS']['caching']['cacheConfigurations']['hash']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['imagesizes']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['pages']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['rootline']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $testbase->setUpLocalConfiguration($this->instancePath, $localConfiguration, $this->configurationToUseInTestInstance);
            $testbase->setUpPackageStates(
                $this->instancePath,
                $defaultCoreExtensionsToLoad,
                $this->coreExtensionsToLoad,
                $this->testExtensionsToLoad,
                $frameworkExtension
            );

            // **********************************************************************
            // Custom code to cache Package Cache
            // **********************************************************************
            $pstate_file = $this->instancePath . '/typo3conf/PackageStates.php';

            // make sure, to only cache the same combination of Packages
            $package_md5 = md5(file_get_contents($pstate_file));

            // set cache Path next to function Testing Folder
            $cache_dir = dirname($this->instancePath) . '/cache';
            $cached_di = $cache_dir . '/di_' . $package_md5 . '.php';

            // load modification Time (which is different, since it was just created)
            $mTime = @filemtime($pstate_file);

            // generate cache identifer, the same way typo3 does
            $cacheIdentifier = md5(new Typo3Version() . $pstate_file . $mTime);
            $baseIdentifier = sha1((new Typo3Version())->getVersion() . $this->instancePath . $cacheIdentifier);
            $current_cache_identifier = 'DependencyInjectionContainer_' . $baseIdentifier;

            // if there is a cache file, copy it over
            if (is_file($cached_di)) {
                // create DI container cache folder
                mkdir($this->instancePath . '/typo3temp/var/cache/code/di/', 0755, true);

                // load cache and replace IDENTIFIER with current Identifier
                $cache = file_get_contents($cached_di);
                file_put_contents($this->instancePath . '/typo3temp/var/cache/code/di/' . $current_cache_identifier . '.php', str_replace('######CACHE_IDENTIFIER######', $current_cache_identifier, $cache));
                unset($cache);
            }

            // **********************************************************************

            $this->container = $testbase->setUpBasicTypo3Bootstrap($this->instancePath);

            // **********************************************************************
            // Custom code to cache Package Cache
            // **********************************************************************
            // if there was no cache file, copy the generated over
            if (!is_file($cached_di)) {
                // create cache directory if not present
                if (!is_dir($cache_dir)) {
                    mkdir($cache_dir, 0755, true);
                }

                // replace current identifier with Placeholder
                $cache = file_get_contents($this->instancePath . '/typo3temp/var/cache/code/di/' . $current_cache_identifier . '.php');
                file_put_contents($cached_di, str_replace($current_cache_identifier, '######CACHE_IDENTIFIER######', $cache));
                unset($cache);
            }
            // **********************************************************************

            if ($this->initializeDatabase) {
                if ($dbDriver !== 'pdo_sqlite') {
                    $testbase->setUpTestDatabase($dbName, $originalDatabaseName);
                } else {
                    $testbase->setUpTestDatabase($dbPathSqlite, $originalDatabaseName);
                }
            }
            $testbase->loadExtensionTables();
            if ($this->initializeDatabase) {
                $testbase->createDatabaseStructure($this->container);
                if ($dbDriver === 'pdo_sqlite') {
                    // Copy sqlite file '/path/functional-sqlite-dbs/test_123.sqlite' to
                    // '/path/functional-sqlite-dbs/test_123.empty.sqlite'. This is re-used for consecutive tests.
                    copy($dbPathSqlite, $dbPathSqliteEmpty);
                }
            }
        }
        BaseTestCase::setUp();
    }

    /**
     * Custom tearDown() unsets private variables, which are copied here
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->container);
    }

    /**
     * Returns the custom TYPO3 dependency injection container
     * containing all public services.
     */
    protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * copy of parent function, to make isFirstTest cache work
     *
     * @param callable|null $createCallback
     * @param callable|null $restoreCallback
     * @throws \Doctrine\DBAL\Exception
     */
    protected function withDatabaseSnapshot(?callable $createCallback = null, ?callable $restoreCallback = null): void
    {
        $connection = $this->getConnectionPool()->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $accessor = new DatabaseAccessor($connection);
        $snapshot = DatabaseSnapshot::instance();
        if ($this->isFirstTest) {
            if ($createCallback) {
                $createCallback();
            }
            $snapshot->create($accessor, $connection);
        } else {
            $snapshot->restore($accessor, $connection);
            if ($restoreCallback) {
                $restoreCallback();
            }
        }
    }

    /**
     * Import static content into database, if needed
     */
    protected function importStaticTablesDataIntoDatabase(): void
    {
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $sqlCode = $sqlReader->getTablesDefinitionString(true);

        $insertStatements = $sqlReader->getInsertStatementArray($sqlCode);
        $schemaMigrationService->importStaticData($insertStatements);
    }
}
