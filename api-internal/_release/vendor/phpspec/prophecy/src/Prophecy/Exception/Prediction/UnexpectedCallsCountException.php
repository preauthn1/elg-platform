<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\Exception\Prediction;

use Prophecy\Prophecy\MethodProphecy;

class UnexpectedCallsCountException extends UnexpectedCallsException
{
    private $expectedCount;

    public function __construct($message, MethodProphecy $methodProphecy, $count, array $calls)
    {
        parent::__construct($message, $methodProphecy, $calls);

        $this->expectedCount = intval($count);
    }

    public function getExpectedCount()
    {
        return $this->expectedCount;
    }
}
