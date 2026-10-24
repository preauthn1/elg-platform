<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\Doubler\ClassPatch;

use Prophecy\Doubler\Generator\Node\ClassNode;

/**
 * Class patch interface.
 * Class patches extend doubles functionality or help
 * Prophecy to avoid some internal PHP bugs.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
interface ClassPatchInterface
{
    /**
     * Checks if patch supports specific class node.
     *
     * @param ClassNode $node
     *
     * @return bool
     */
    public function supports(ClassNode $node);

    /**
     * Applies patch to the specific class node.
     *
     * @param ClassNode $node
     * @return void
     */
    public function apply(ClassNode $node);

    /**
     * Returns patch priority, which determines when patch will be applied.
     *
     * @return int Priority number (higher - earlier)
     */
    public function getPriority();
}
