<?php

/** @noinspection PhpUnused */

namespace ScoutNet\TestingTools\Domain\Model;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2020 Stefan "Mütze" Horst <muetze@scoutnet.de>, ScoutNet
 *
 *  All rights reserved
 ***************************************************************/

use Doctrine\Common\Annotations\AnnotationReader;
use ReflectionClass;
use ReflectionException;
use ScoutNet\TestingTools\Mocks\DataMapperMock;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Annotation\ORM\Cascade;
use TYPO3\CMS\Extbase\Annotation\ORM\Lazy;
use TYPO3\CMS\Extbase\Annotation\ORM\Transient;
use TYPO3\CMS\Extbase\Annotation\Validate;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy;

/**
 * Annotation Test for class specified in testedClass Parameter
 *
 * @copyright Copyright belongs to the respective authors
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 *
 * @author Stefan "Mütze" Horst <muetze@scoutnet.de>
 */
trait AnnotationTestTrait
{
    /**
     * @param ReflectionClass $o
     * @return array
     */
    private function get_use_statements_from_class(ReflectionClass $o): array
    {
        $ns = $o->getNamespaceName();
        $use_statements = [];
        $correct_namespace = false;

        $f = fopen($o->getFileName(), 'rb');
        if ($f) {
            while (($line = fgets($f)) !== false) {
                if (str_starts_with(mb_strtolower(trim($line)), 'namespace')) {
                    $correct_namespace = str_contains($line, $ns);
                }
                if ($correct_namespace && str_starts_with(mb_strtolower(trim($line)), 'use')) {
                    $class = trim(preg_replace('/.*use ([^;]*);.*/', '\1', $line));
                    if (str_contains($class, '\\')) {
                        $short_name = substr($class, strrpos($class, '\\') + 1);
                    } else {
                        $short_name = $class;
                    }
                    $use_statements[$short_name] = $class;
                }
            }
        }
        return $use_statements;
    }

    /**
     * Check all Annotations, if they are used in correct manner
     *
     * @throws ReflectionException
     */
    public function testAnnotations(): void
    {
        // load extbase Configuration
        $definedClasses = require '../Configuration/Extbase/Persistence/Classes.php';
        $tableName = $definedClasses[$this->testedClass]['tableName'];
        $table_properties = $definedClasses[$this->testedClass]['properties'] ?? [];

        // check if tableName is set
        $this->assertNotEmpty($tableName, 'unknown Tablename');

        // load TCA
        if (is_file('../Configuration/TCA/' . $tableName . '.php')) {
            $tcaTable = require '../Configuration/TCA/' . $tableName . '.php';
        } elseif (is_file('../Configuration/TCA/Overrides/' . $tableName . '.php')) {
            if (is_file($this->overriddenTCAFile ?? '')) {
                $GLOBALS['TCA'][$tableName] = require $this->overriddenTCAFile;
            } else {
                $this->fail('Cannot find Original TCA configuration (please set overriddenTCAFile)!' . $tableName);
            }

            // Include Overrides
            require '../Configuration/TCA/Overrides/' . $tableName . '.php';

            $tcaTable = $GLOBALS['TCA'][$tableName];
        } else {
            $this->fail('Cannot find TCA configuration!');
        }

        // start checking Annotations
        $o = new ReflectionClass($this->testedClass);
        $parser = new AnnotationReader();
        $use_statements = $this->get_use_statements_from_class($o);

        // add parent use Statements as well (for Overrides)
        if ($p = get_parent_class($this->testedClass)) {
            $use_statements += $this->get_use_statements_from_class(new ReflectionClass($p));
        }

        foreach ($o->getProperties() as $prop) {
            // TODO: maybe use Typo3 to check those
            // if we redefined the column name use this
            $tableColumnName = $table_properties[$prop->getName()]['fieldName'] ?? GeneralUtility::camelCaseToLowerCaseUnderscored($prop->getName());

            $tableConfig = $tcaTable['columns'][$tableColumnName] ?? [];

            // TODO: reenable this Test!
            //            if (!isset($tableConfig['config'])) {
            //                $this->fail('Config not set for ' . $prop->getName());
            //            }

            // get type
            $type = null;
            if (preg_match('/@var\s+(\S+)/', $prop->getDocComment(), $matches)) {
                $type = $matches[1];

                if (preg_match('/(\S+)<\S+>/', $prop->getDocComment(), $matches)) {
                    $type = $matches[1];
                }
            }

            // TODO: check SQL statements

            // check all Annotations
            foreach ($parser->getPropertyAnnotations($prop) as $annotation) {
                if ($annotation instanceof Lazy) {
                    // Lazy objects should not leak outside the object

                    $all_types = explode('|', $type);

                    // add namespace from use statements
                    $all_types = array_map(static function ($class) use ($use_statements, $o) {return $use_statements[$class] ?? (str_contains($class, '\\') ? $class : $o->getNamespaceName() . '\\' . $class);}, $all_types);

                    // check, that all types exists
                    foreach ($all_types as $class) {
                        if (!class_exists($class)) {
                            $this->fail('Class ' . $class . ' does not exists');
                        }
                    }

                    if (count($all_types) !== 1) {
                        $this->fail($prop->getName() . ': Needs to hold only one Type but has ' . count($all_types) . ' types!');
                    }

                    // has only one object
                    $type = $all_types[0];

                    // generate expected return value
                    $value = new $type();

                    // generate new test object
                    $test = new $this->testedClass();

                    // dataMapper needs to return the expected value
                    $dataMapper = $this->getAccessibleMock(DataMapperMock::class);
                    $dataMapper->method('fetchRelated')->willReturn(null);
                    $dataMapper->method('mapResultToPropertyValue')->willReturn($value);

                    // initialize the Proxy to return the expected value
                    $lazyProxy = new LazyLoadingProxy($test, $prop->getName(), '', $dataMapper);
                    $test->_setProperty($prop->getName(), $lazyProxy);

                    // check if the get Function returns the correct value and not the LazyProxy
                    $function = 'get' . lcfirst($prop->getName());
                    $this->assertEquals($value, $test->$function());
                } elseif ($annotation instanceof Validate) {
                    if ($annotation->validator === 'StringLength') {
                        //                        $min = $annotation->options['minimum']??null;
                        $max = $annotation->options['maximum'] ?? null;

                        if ($max !== null) {
                            $this->assertEquals($max, $tableConfig['config']['max'] ?? null, 'max value for ' . $prop->getName() . ' is wrong.');
                        }
                    } elseif ($annotation->validator === 'Integer') {
                        $this->assertStringContainsStringIgnoringCase('int', $tableConfig['config']['eval'] ?? '', $prop->getName() . ' needs to be int.');
                    } elseif ($annotation->validator === 'Url') {
                        $this->assertStringContainsStringIgnoringCase('domainname', $tableConfig['config']['eval'] ?? '', $prop->getName() . ' needs to be domainname.');
                    } elseif ($annotation->validator === 'Float') {
                        // TODO: find the correct validation here!!
                    } elseif ($annotation->validator == 'EmailAddress') {
                        $this->assertStringContainsStringIgnoringCase('email', $tableConfig['config']['eval'] ?? '', $prop->getName() . ' needs to be email.');
                    } elseif ($annotation->validator === 'NumberRange') {
                        $min = $annotation->options['minimum'] ?? null;
                        $max = $annotation->options['maximum'] ?? null;

                        $this->assertStringContainsStringIgnoringCase('int', $tableConfig['config']['eval'] ?? '', $prop->getName() . ' needs to be int.');

                        if ($min !== null) {
                            $this->assertEquals($min, $tableConfig['config']['range']['lower'] ?? null, 'lower value for ' . $prop->getName() . ' is wrong.');
                        }
                        if ($max !== null) {
                            $this->assertEquals($max, $tableConfig['config']['range']['upper'] ?? null, 'upper value for ' . $prop->getName() . ' is wrong.');
                        }
                    } elseif ($annotation->validator === 'NotEmpty') {
                        if ($tableConfig['config']['type'] === 'input') {
                            $this->assertEquals(true, $tableConfig['config']['required'] ?? false, $prop->getName() . ' is required.');
                        } elseif ($tableConfig['config']['type'] === 'text') {
                            $this->assertEquals(true, $tableConfig['config']['required'] ?? false, $prop->getName() . ' is required.');
                        } elseif ($tableConfig['config']['type'] === 'group') {
                            $this->assertEquals(1, $tableConfig['config']['minitems'] ?? 0, $prop->getName() . ' is required.');
                        } elseif ($tableConfig['config']['type'] === 'select') {
                            $this->assertEquals('selectSingle', $tableConfig['config']['renderType'] ?? '', $prop->getName() . ': renderType needs to be selectSingle.');
                            $this->assertEquals([], $tableConfig['config']['items'] ?? [], $prop->getName() . ': items needs to be empty.'); // TODO: check, if this is allways the case
                            $this->assertEquals('notSET_1641824444', $tableConfig['config']['default'] ?? 'notSET_1641824444', $prop->getName() . ': default must not be set!'); // TODO: check, if this is allways the case
                        } elseif ($tableConfig['config']['type'] === 'datetime') {
                            $this->assertEquals(true, $tableConfig['config']['required'] ?? false, $prop->getName() . ' is required.');
                        } else {
                            // TODO: check that this is not empty for other items
                            print 'unhandled NotEmpty for type ' . $tableConfig['config']['type'] . "\n";
                            /** @noinspection ForgottenDebugOutputInspection */
                            var_dump($tableConfig);
                        }
                    } else {
                        print 'unhandled Validator' . "\n";
                        /** @noinspection ForgottenDebugOutputInspection */
                        var_dump($annotation);
                    }
                } elseif ($annotation instanceof Transient) {
                    // TODO: check if we can check those annotations
                    $this->assertEmpty($tableConfig, 'Transient property ' . $prop->getName() . ' has database fields.');
                } elseif ($annotation instanceof Cascade) {
                    // TODO: check if we can check those annotations
                } else {
                    // TODO: break for unknown annotations
                    print 'unknown annotation' . "\n";
                    /** @noinspection ForgottenDebugOutputInspection */
                    var_dump($annotation);
                }
            }
        }
    }
}
