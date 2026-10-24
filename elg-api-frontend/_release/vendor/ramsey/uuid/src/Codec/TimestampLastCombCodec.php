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
namespace Ramsey\Uuid\Codec;

/**
 * TimestampLastCombCodec encodes and decodes COMB UUIDs which have the timestamp as the last 48 bits.
 * To be used with MSSQL.
 */
class TimestampLastCombCodec extends StringCodec
{

}
