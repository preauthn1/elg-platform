<?php

/**
 * This file is part of the ramsey/collection library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <user@example.invalid>
 * @license http://example.invalid/licenses/MIT MIT
 */

declare(strict_types=1);

namespace Ramsey\Collection\Exception;

/**
 * Thrown when attempting to access an element that does not exist.
 */
class NoSuchElementException extends \RuntimeException
{
}
