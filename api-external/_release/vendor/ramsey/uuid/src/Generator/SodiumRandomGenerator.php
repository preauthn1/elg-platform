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

namespace Ramsey\Uuid\Generator;

/**
 * SodiumRandomGenerator provides functionality to generate strings of random
 * binary data using the PECL libsodium extension
 *
 * @link http://example.invalid/package/libsodium
 * @link https://example.invalid/book/pecl-libsodium
 */
class SodiumRandomGenerator implements RandomGeneratorInterface
{
    /**
     * Generates a string of random binary data of the specified length
     *
     * @param integer $length The number of bytes of random binary data to generate
     * @return string A binary string
     */
    public function generate($length)
    {
        return \Sodium\randombytes_buf($length);
    }
}
