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

use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\MockInterface;

/**
 * @requires PHP 5.6.0-dev
 */
class MockingOldStyleConstructorTest extends MockeryTestCase
{
    /**
     * @issue issue/139
     */
    public function testCanMockClassWithOldStyleConstructorAndArguments()
    {
        $this->assertInstanceOf(MockInterface::class, mock('MockeryTest_OldStyleConstructor'));
    }
}

class MockeryTest_OldStyleConstructor
{
    public function MockeryTest_OldStyleConstructor($arg)
    {
    }
}
