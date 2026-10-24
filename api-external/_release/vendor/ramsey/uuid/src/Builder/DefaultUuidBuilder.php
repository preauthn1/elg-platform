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
use Ramsey\Uuid\Converter\NumberConverterInterface;
use Ramsey\Uuid\Uuid;

/**
 * DefaultUuidBuilder is the default UUID builder for ramsey/uuid; it builds
 * instances of Uuid objects
 */
class DefaultUuidBuilder implements UuidBuilderInterface
{
    /**
     * @var NumberConverterInterface
     */
    private $converter;

    /**
     * Constructs the DefaultUuidBuilder
     *
     * @param NumberConverterInterface $converter The number converter to use when constructing the Uuid
     */
    public function __construct(NumberConverterInterface $converter)
    {
        $this->converter = $converter;
    }

    /**
     * Builds a Uuid
     *
     * @param CodecInterface $codec The codec to use for building this Uuid
     * @param array $fields An array of fields from which to construct the Uuid;
     *     see {@see \Ramsey\Uuid\UuidInterface::getFieldsHex()} for array structure.
     * @return Uuid
     */
    public function build(CodecInterface $codec, array $fields)
    {
        return new Uuid($fields, $this->converter, $codec);
    }
}
