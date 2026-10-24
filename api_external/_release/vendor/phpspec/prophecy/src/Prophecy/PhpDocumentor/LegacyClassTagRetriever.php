<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\PhpDocumentor;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tag\MethodTag as LegacyMethodTag;

/**
 * @author Théo FIDRY <user@example.invalid>
 *
 * @internal
 */
final class LegacyClassTagRetriever implements MethodTagRetrieverInterface
{
    /**
     * @param \ReflectionClass $reflectionClass
     *
     * @return LegacyMethodTag[]
     */
    public function getTagList(\ReflectionClass $reflectionClass)
    {
        $phpdoc = new DocBlock($reflectionClass->getDocComment());

        return $phpdoc->getTagsByName('method');
    }
}
