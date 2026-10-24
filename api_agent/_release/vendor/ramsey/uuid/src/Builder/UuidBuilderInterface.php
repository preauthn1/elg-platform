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

namespace Ramsey\Uuid\Builder;

use Ramsey\Uuid\Codec\CodecInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * UuidBuilderInterface builds instances UuidInterface
 */
interface UuidBuilderInterface
{
    /**
     * Builds an instance of a UuidInterface
     *
     * @param CodecInterface $codec The codec to use for building this UuidInterface instance
     * @param array $fields An array of fields from which to construct a UuidInterface instance;
     *     see {@see \Ramsey\Uuid\UuidInterface::getFieldsHex()} for array structure.
     * @return UuidInterface
     */
    public function build(CodecInterface $codec, array $fields);
}
