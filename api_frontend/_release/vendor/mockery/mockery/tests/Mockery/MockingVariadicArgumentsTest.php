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

class MockingVariadicArgumentsTest extends MockeryTestCase
{
    /** @test */
    public function shouldAllowMockingVariadicArguments()
    {
        $mock = mock("test\Mockery\TestWithVariadicArguments");

        $mock->shouldReceive("foo")->andReturn("notbar");
        $this->assertEquals("notbar", $mock->foo());
    }
}


abstract class TestWithVariadicArguments
{
    public function foo(...$bar)
    {
        return $bar;
    }
}
