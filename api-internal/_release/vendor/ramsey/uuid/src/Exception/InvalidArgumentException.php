<?php

/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <user@example.invalid>
 * @license http://example.invalid/licenses/MIT MIT
 */

declare(strict_types=1);

namespace Ramsey\Uuid\Exception;

use InvalidArgumentException as PhpInvalidArgumentException;

/**
 * Thrown to indicate that the argument received is not valid
 */
class InvalidArgumentException extends PhpInvalidArgumentException
{
}
