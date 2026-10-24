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

namespace Ramsey\Uuid\Fields;

use Serializable;

/**
 * UUIDs are comprised of unsigned integers, the bytes of which are separated
 * into fields and arranged in a particular layout defined by the specification
 * for the variant
 *
 * @psalm-immutable
 */
interface FieldsInterface extends Serializable
{
    /**
     * Returns the bytes that comprise the fields
     */
    public function getBytes(): string;
}
