<?php

namespace ScoutNet\TestingTools\Domain\Model;

use ReflectionException;
use ReflectionProperty;
use TypeError;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

abstract class ModelUnitTestCase extends UnitTestCase
{
    /**
     * @var AbstractEntity
     */
    protected $subject;

    /**
     * @var string
     */
    protected string $testedClass;

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

                    switch ($ret_type) {
                        case 'integer':
                        case 'int':
                            $this->expectException(TypeError::class);
                            // check if only numbers are allowed
                            $this->subject->$setter('test');
                            self::assertEquals(
                                0,
                                $this->subject->$getter(),
                                $attribute . ' allows String content, but is integer.'
                            );

                            // check if correct numbers are returned
                            $this->subject->$setter(23);
                            self::assertEquals(
                                23,
                                $this->subject->$getter(),
                                $attribute . ' does not return what was saved.'
                            );
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
                    }
                } catch (ReflectionException $exception) {
                    // ignore attribute
                }
            }
        }
    }

    use AnnotationTestTrait;
}
