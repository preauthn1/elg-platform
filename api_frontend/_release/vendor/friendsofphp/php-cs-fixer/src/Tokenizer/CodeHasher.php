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

namespace PhpCsFixer\Tokenizer;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 *
 * @internal
 */
final class CodeHasher
{
    private function __construct()
    {
        // cannot create instance of util. class
    }

    /**
     * Calculate hash for code.
     *
     * @param string $code
     *
     * @return string
     */
    public static function calculateCodeHash($code)
    {
        return (string) crc32($code);
    }
}
