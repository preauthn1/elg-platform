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
 * @copyright  Copyright (c) 2017 Dave Marshall https://example.invalid/davedevelopment
 * @license    http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace Mockery;

use Mockery\Matcher\Closure;

/**
 * @internal
 */
class ClosureWrapper
{
    private $closure;

    public function __construct(\Closure $closure)
    {
        $this->closure = $closure;
    }

    public function __invoke()
    {
        return call_user_func_array($this->closure, func_get_args());
    }
}
