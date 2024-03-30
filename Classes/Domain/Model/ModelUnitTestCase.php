<?php

namespace ScoutNet\TestingTools\Domain\Model;

use DateTime;
use ReflectionException;
use ReflectionProperty;
use TypeError;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

abstract class ModelUnitTestCase extends UnitTestCase
{
    use AnnotationTestTrait;
    /**
     * @var AbstractEntity
     */
    protected $subject;

    /**
     * @var string
     */
    protected string $testedClass;

    /**
     * @var array
     */
    protected array $map_classes = [];

    protected function setUp(): void
    {
        $this->subject = new $this->testedClass();
    }

    protected function tearDown(): void
    {
        unset($this->subject);
    }

    /**
     * @param $str
     * @param string $tag
     * @return string
     */
    protected function getDocComment($str, string $tag = ''): string
    {
        if (empty($tag)) {
            return $str;
        }

        $matches = [];
        preg_match('/' . $tag . ' (.*)(\\r\\n|\\r|\\n)/U', $str, $matches);

        if (isset($matches[1])) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * This test tests all getter/setter combination, which are not tested in this class
     *
     * @throws ReflectionException
     */
    public function testNotTestedStringAndIntegerAttributes(): void
    {
        foreach (get_class_methods(get_class($this->subject)) as $method) {
            $type = substr($method, 0, 3);
            $attribute = lcfirst(substr($method, 3));
            $setter = 'set' . ucfirst($attribute);
            $getter = 'get' . ucfirst($attribute);

            // only test, if getter and setter exists and local testClass does not implement this test
            if (
                $type === 'get' &&
                method_exists($this->subject, $setter) &&
                !method_exists($this, 'test' . ucfirst($attribute))
            ) {
                try {
                    $a = new ReflectionProperty($this->subject, $attribute);
                    $ret_type = $this->getDocComment($a->getDocComment(), '@var');
                    $nullable = false;

                    // sort and remove null
                    if (str_contains($ret_type, '|')) {
                        $ret_type = explode('|', $ret_type);
                        if (($key = array_search('null', $ret_type)) !== false) {
                            unset($ret_type[$key]);
                            $nullable = true;
                        }
                        sort($ret_type);
                        $ret_type = implode('|', $ret_type);
                    }

                    if ($nullable) {
                        self::assertNull($this->subject->$getter(), $attribute . 'does not return null as default.');
                    }

                    $ret_type = strtolower($ret_type);

                    switch ($ret_type) {
                        case 'integer':
                        case 'int':
                            try {
                                // check if only numbers are allowed
                                $this->subject->$setter('test');

                                self::fail($attribute . ' allows String content, but is integer.');
                            } catch (TypeError) {
                            }

                            // check if correct numbers are returned
                            $this->subject->$setter(23);
                            self::assertEquals(
                                23,
                                $this->subject->$getter(),
                                $attribute . ' does not return what was saved.'
                            );
                            break;
                        case 'bool':
                            $isser = 'is' . ucfirst($attribute);
                            $haser = 'has' . ucfirst($attribute);
                            // check if correct numbers are returned
                            $this->subject->$setter(true);
                            self::assertTrue(
                                $this->subject->$getter(),
                                $getter . ' does not return true.'
                            );
                            if (method_exists($this->subject, $isser)) {
                                self::assertTrue(
                                    $this->subject->$isser(),
                                    $isser . ' does not return true.'
                                );
                            }
                            if (method_exists($this->subject, $haser)) {
                                self::assertTrue(
                                    $this->subject->$haser(),
                                    $haser . ' does not return true.'
                                );
                            }

                            $this->subject->$setter(false);
                            self::assertFalse(
                                $this->subject->$getter(),
                                $getter . ' does not return what was saved.'
                            );
                            if (method_exists($this->subject, $isser)) {
                                self::assertFalse(
                                    $this->subject->$isser(),
                                    $isser . ' does not return false.'
                                );
                            }
                            if (method_exists($this->subject, $haser)) {
                                self::assertFalse(
                                    $this->subject->$haser(),
                                    $haser . ' does not return false.'
                                );
                            }
                            break;
                        case 'string':
                            // test, if we can set the attribute, and get it back from the getter
                            $this->subject->$setter('Test' . $attribute);
                            self::assertEquals(
                                'Test' . $attribute,
                                $this->subject->$getter(),
                                $attribute . ' does not return what was saved.'
                            );
                            break;
                        case 'datetime':
                            try {
                                // check if only DateTime is allowed
                                $this->subject->$setter('1982-09-16');

                                self::fail($attribute . ' allows non DateTime Values.');
                            } catch (TypeError) {
                            }

                            // check if correct DateTimes are allowed
                            $testDate = new DateTime();
                            $this->subject->$setter($testDate);

                            self::assertEquals($testDate, $this->subject->$getter());
                            break;
                    }
                } catch (ReflectionException $exception) {
                    // ignore attribute
                }
            }
        }
    }
}
