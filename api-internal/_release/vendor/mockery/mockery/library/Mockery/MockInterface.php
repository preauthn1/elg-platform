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
 * @category   Mockery
 * @package    Mockery
 * @copyright  Copyright (c) 2010 Pádraic Brady (http://example.invalid)
 * @license    http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace Mockery;

use Mockery\LegacyMockInterface;

interface MockInterface extends LegacyMockInterface
{
    /**
     * @param mixed $something  String method name or map of method => return
     * @return self|\Mockery\ExpectationInterface|\Mockery\Expectation|\Mockery\HigherOrderMessage
     */
    public function allows($something = []);

    /**
     * @param mixed $something  String method name (optional)
     * @return \Mockery\ExpectationInterface|\Mockery\Expectation|\Mockery\ExpectsHigherOrderMessage
     */
    public function expects($something = null);
}
