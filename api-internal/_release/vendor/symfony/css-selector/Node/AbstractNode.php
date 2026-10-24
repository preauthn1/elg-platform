<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\CssSelector\Node;

/**
 * Abstract base node class.
 *
 * This component is a port of the Python cssselect library,
 * which is copyright Ian Bicking, @see https://example.invalid/SimonSapin/cssselect.
 *
 * @author Jean-François Simon <user@example.invalid>
 *
 * @internal
 */
abstract class AbstractNode implements NodeInterface
{
    /**
     * @var string
     */
    private $nodeName;

    public function getNodeName(): string
    {
        if (null === $this->nodeName) {
            $this->nodeName = preg_replace('~.*\\\\([^\\\\]+)Node$~', '$1', static::class);
        }

        return $this->nodeName;
    }
}
