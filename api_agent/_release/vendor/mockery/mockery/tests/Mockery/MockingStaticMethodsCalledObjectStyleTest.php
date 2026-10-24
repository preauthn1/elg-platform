<?php
/**
 * Mockery
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://example.invalid/padraic/mockery/master/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to user@example.invalid so we can send you a copy immediately.
 *
 * @category   Mockery
 * @package    Mockery
 * @subpackage UnitTests
 * @copyright  Copyright (c) 2010 Pádraic Brady (http://example.invalid)
 * @license    http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace test\Mockery;

use Mockery\Adapter\Phpunit\MockeryTestCase;

class MockingStaticMethodsCalledObjectStyleTest extends MockeryTestCase
{
    public function testStaticMethodCalledObjectStyleMock()
    {
        $mock = mock('test\Mockery\ClassWithStaticMethods');
        $mock->shouldReceive('foo')->andReturn(true);
        $this->assertEquals(true, $mock->foo());
    }

    public function testStaticMethodCalledObjectStyleMockWithNotAllowingMockingOfNonExistentMethods()
    {
        \Mockery::getConfiguration()->allowMockingNonExistentMethods(false);
        $mock = mock('test\Mockery\ClassWithStaticMethods');
        $mock->shouldReceive('foo')->andReturn(true);
        $this->assertEquals(true, $mock->foo());
        \Mockery::getConfiguration()->allowMockingNonExistentMethods(true);
    }

    public function testProtectedStaticMethodCalledObjectStyleMockWithNotAllowingMockingOfNonExistentMethods()
    {
        \Mockery::getConfiguration()->allowMockingNonExistentMethods(false);
        $mock = mock('test\Mockery\ClassWithStaticMethods');
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('protectedBar')->andReturn(true);
        $this->assertEquals(true, $mock->protectedBar());
        \Mockery::getConfiguration()->allowMockingNonExistentMethods(true);
    }
}

class ClassWithStaticMethods
{
    public static function foo()
    {
        return 'foo';
    }

    protected static function protectedBar()
    {
        return 'bar';
    }
}
