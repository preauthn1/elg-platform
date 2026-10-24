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

namespace PhpCsFixer\Linter;

/**
 * Exception that is thrown when the chosen linter is not available on the environment.
 *
 * @author Dariusz Rumiński <user@example.invalid>
 */
class UnavailableLinterException extends \RuntimeException
{
}
