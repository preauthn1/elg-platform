<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *     Dariusz Rumiński <user@example.invalid>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer;

use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 * @author SpacePossum
 */
interface DefinedFixerInterface extends FixerInterface
{
    /**
     * Returns the definition of the fixer.
     *
     * @return FixerDefinitionInterface
     */
    public function getDefinition();
}
