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

namespace Mockery\Loader;

use Mockery\Generator\MockConfiguration;
use Mockery\Generator\MockDefinition;
use PHPUnit\Framework\TestCase;

abstract class LoaderTestCase extends TestCase
{
    /**
     * @test
     */
    public function loadLoadsTheCode()
    {
        $className = 'Mock_' . uniqid();
        $config = new MockConfiguration(array(), array(), array(), $className);
        $code = "<?php class $className { } ";

        $definition = new MockDefinition($config, $code);

        $this->getLoader()->load($definition);

        $this->assertTrue(class_exists($className));
    }

    abstract public function getLoader();
}
