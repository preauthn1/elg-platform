<?php
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright 2010-2015 Mike van Riel<user@example.invalid>
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */

namespace phpDocumentor\Reflection\Types;

use phpDocumentor\Reflection\Type;

/**
 * Value Object representing the pseudo-type 'void'.
 *
 * Void is generally only used when working with return types as it signifies that the method intentionally does not
 * return any value.
 */
final class Void_ implements Type
{
    /**
     * Returns a rendered output of the Type as it would be used in a DocBlock.
     *
     * @return string
     */
    public function __toString()
    {
        return 'void';
    }
}
