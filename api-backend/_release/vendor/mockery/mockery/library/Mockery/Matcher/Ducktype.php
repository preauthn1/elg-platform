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

namespace Mockery\Matcher;

class Ducktype extends MatcherAbstract
{
    /**
     * Check if the actual value matches the expected.
     *
     * @param mixed $actual
     * @return bool
     */
    public function match(&$actual)
    {
        if (!is_object($actual)) {
            return false;
        }
        foreach ($this->_expected as $method) {
            if (!method_exists($actual, $method)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return a string representation of this Matcher
     *
     * @return string
     */
    public function __toString()
    {
        return '<Ducktype[' . implode(', ', $this->_expected) . ']>';
    }
}
