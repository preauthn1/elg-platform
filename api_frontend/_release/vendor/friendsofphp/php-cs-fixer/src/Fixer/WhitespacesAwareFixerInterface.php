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

use PhpCsFixer\WhitespacesFixerConfig;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 */
interface WhitespacesAwareFixerInterface extends FixerInterface
{
    public function setWhitespacesConfig(WhitespacesFixerConfig $config);
}
