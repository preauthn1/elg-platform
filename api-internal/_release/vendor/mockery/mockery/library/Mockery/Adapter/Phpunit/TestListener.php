<?php
/**
 * Mockery
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://example.invalid/padraic/mockery/blob/master/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to user@example.invalid so we can send you a copy immediately.
 *
 * @category  Mockery
 * @package   Mockery
 * @copyright Copyright (c) 2010 Pádraic Brady (http://example.invalid)
 * @license   http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace Mockery\Adapter\Phpunit;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\TestListener as PHPUnitTestListener;

class TestListener implements PHPUnitTestListener
{
    use TestListenerDefaultImplementation;

    private $trait;

    public function __construct()
    {
        $this->trait = new TestListenerTrait();
    }

    public function endTest(Test $test, float $time): void
    {
        $this->trait->endTest($test, $time);
    }

    public function startTestSuite(TestSuite $suite): void
    {
        $this->trait->startTestSuite();
    }
}
