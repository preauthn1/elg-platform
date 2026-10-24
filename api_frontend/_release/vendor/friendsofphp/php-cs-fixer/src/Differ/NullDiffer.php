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

namespace PhpCsFixer\Differ;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 */
final class NullDiffer implements DifferInterface
{
    /**
     * {@inheritdoc}
     */
    public function diff($old, $new)
    {
        return '';
    }
}
