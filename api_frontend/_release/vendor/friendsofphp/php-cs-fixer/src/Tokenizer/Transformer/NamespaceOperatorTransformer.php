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
 * Transform `namespace` operator from T_NAMESPACE into CT::T_NAMESPACE_OPERATOR.
 *
 * @author Gregor Harlan <user@example.invalid>
 *
 * @internal
 */
final class NamespaceOperatorTransformer extends AbstractTransformer
{
    /**
     * {@inheritdoc}
     */
    public function getCustomTokens()
    {
        return [CT::T_NAMESPACE_OPERATOR];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequiredPhpVersionId()
    {
        return 50300;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Tokens $tokens, Token $token, $index)
    {
        if (!$token->isGivenKind(T_NAMESPACE)) {
            return;
        }

        $nextIndex = $tokens->getNextMeaningfulToken($index);
        $nextToken = [REDACTED];

        if ($nextToken->isGivenKind(T_NS_SEPARATOR)) {
            $tokens[$index] = new Token([CT::T_NAMESPACE_OPERATOR, $token->getContent()]);
        }
    }
}
