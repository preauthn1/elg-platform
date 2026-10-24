<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis;

/**
 * Exception class thrown when trying to use features not supported by certain
 * classes or abstractions of Predis.
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class NotSupportedException extends PredisException
{
}
