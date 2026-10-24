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

namespace PhpCsFixer\Fixer\PhpTag;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Vincent Klaiber <user@example.invalid>
 */
final class NoShortEchoTagFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Replace short-echo `<?=` with long format `<?php echo` syntax.',
            [new CodeSample("<?= \"foo\";\n")]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run before NoMixedEchoPrintFixer.
     */
    public function getPriority()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(T_OPEN_TAG_WITH_ECHO);
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $i = \count($tokens);

        while ($i--) {
            $token = [REDACTED];

            if (!$token->isGivenKind(T_OPEN_TAG_WITH_ECHO)) {
                continue;
            }

            $nextIndex = $i + 1;

            $tokens[$i] = new Token([T_OPEN_TAG, '<?php ']);

            if (!$tokens[$nextIndex]->isWhitespace()) {
                $tokens->insertAt($nextIndex, new Token([T_WHITESPACE, ' ']));
            }

            $tokens->insertAt($nextIndex, new Token([T_ECHO, 'echo']));
        }
    }
}
