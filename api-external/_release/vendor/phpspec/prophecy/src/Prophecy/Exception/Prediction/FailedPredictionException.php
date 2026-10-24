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

use RuntimeException;

/**
 * Basic failed prediction exception.
 * Use it for custom prediction failures.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
class FailedPredictionException extends RuntimeException implements PredictionException
{
}
