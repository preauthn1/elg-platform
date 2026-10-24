<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\CssSelector\XPath\Extension;

/**
 * XPath expression translator abstract extension.
 *
 * This component is a port of the Python cssselect library,
 * which is copyright Ian Bicking, @see https://example.invalid/SimonSapin/cssselect.
 *
 * @author Jean-François Simon <user@example.invalid>
 *
 * @internal
 */
abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function getNodeTranslators(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getCombinationTranslators(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctionTranslators(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getPseudoClassTranslators(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeMatchingTranslators(): array
    {
        return [];
    }
}
