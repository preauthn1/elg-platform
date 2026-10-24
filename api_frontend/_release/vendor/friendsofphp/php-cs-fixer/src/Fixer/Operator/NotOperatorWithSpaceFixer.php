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

namespace PhpCsFixer\Fixer\Operator;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Javier Spagnoletti <user@example.invalid>
 */
final class NotOperatorWithSpaceFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Logical NOT operators (`!`) should have leading and trailing whitespaces.',
            [new CodeSample(
                '<?php

if (!$bar) {
    echo "Help!";
}
'
            )]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run after UnaryOperatorSpacesFixer.
     */
    public function getPriority()
    {
        return -10;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound('!');
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        for ($index = $tokens->count() - 1; $index >= 0; --$index) {
            $token = [REDACTED];

            if ($token->equals('!')) {
                if (!$tokens[$index + 1]->isWhitespace()) {
                    $tokens->insertAt($index + 1, new Token([T_WHITESPACE, ' ']));
                }

                if (!$tokens[$index - 1]->isWhitespace()) {
                    $tokens->insertAt($index, new Token([T_WHITESPACE, ' ']));
                }
            }
        }
    }
}
