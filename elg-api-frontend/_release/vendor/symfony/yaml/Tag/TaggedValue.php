<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Yaml\Tag;

/**
 * @author Nicolas Grekas <user@example.invalid>
 * @author Guilhem N. <user@example.invalid>
 */
final class TaggedValue
{
    private $tag;
    private $value;

    public function __construct(string $tag, $value)
    {
        $this->tag = $tag;
        $this->value = $value;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getValue()
    {
        return $this->value;
    }
}
