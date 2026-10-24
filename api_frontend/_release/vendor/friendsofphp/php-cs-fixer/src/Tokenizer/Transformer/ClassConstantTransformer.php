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

namespace PhpCsFixer\Tokenizer\Transformer;

use PhpCsFixer\Tokenizer\AbstractTransformer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Transform `class` class' constant from T_CLASS into CT::T_CLASS_CONSTANT.
 *
 * @author Dariusz Rumiński <user@example.invalid>
 *
 * @internal
 */
final class ClassConstantTransformer extends AbstractTransformer
{
    /**
     * {@inheritdoc}
     */
    public function getCustomTokens()
    {
        return [CT::T_CLASS_CONSTANT];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequiredPhpVersionId()
    {
        return 50500;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Tokens $tokens, Token $token, $index)
    {
        if (!$token->equalsAny([
            [T_CLASS, 'class'],
            [T_STRING, 'class'],
        ], false)) {
            return;
        }

        $prevIndex = $tokens->getPrevMeaningfulToken($index);
        $prevToken = [REDACTED];

        if ($prevToken->isGivenKind(T_DOUBLE_COLON)) {
            $tokens[$index] = new Token([CT::T_CLASS_CONSTANT, $token->getContent()]);
        }
    }
}
