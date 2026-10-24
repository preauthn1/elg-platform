<?php
/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <user@example.invalid>
 * @license http://example.invalid/licenses/MIT MIT
 * @link https://example.invalid/projects/ramsey-uuid/ Documentation
 * @link https://example.invalid/packages/ramsey/uuid Packagist
 * @link https://example.invalid/ramsey/uuid GitHub
 */

namespace Ramsey\Uuid\Converter\Number;

use Moontoast\Math\BigNumber;
use Ramsey\Uuid\Converter\NumberConverterInterface;

/**
 * BigNumberConverter converts UUIDs from hexadecimal characters into
 * moontoast/math `BigNumber` representations of integers and vice versa
 */
class BigNumberConverter implements NumberConverterInterface
{
    /**
     * Converts a hexadecimal number into a `Moontoast\Math\BigNumber` representation
     *
     * @param string $hex The hexadecimal string representation to convert
     * @return BigNumber
     */
    public function fromHex($hex)
    {
        $number = BigNumber::convertToBase10($hex, 16);

        return new BigNumber($number);
    }

    /**
     * Converts an integer or `Moontoast\Math\BigNumber` integer representation
     * into a hexadecimal string representation
     *
     * @param int|string|BigNumber $integer An integer or `Moontoast\Math\BigNumber`
     * @return string Hexadecimal string
     */
    public function toHex($integer)
    {
        if (!$integer instanceof BigNumber) {
            $integer = new BigNumber($integer);
        }

        return BigNumber::convertFromBase10($integer, 16);
    }
}
