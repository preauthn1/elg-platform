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

namespace PhpCsFixer\Fixer\NamespaceNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Fixer for rules defined in PSR2 ¶3.
 *
 * @author Dariusz Rumiński <user@example.invalid>
 */
final class BlankLineAfterNamespaceFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'There MUST be one blank line after the namespace declaration.',
            [
                new CodeSample("<?php\nnamespace Sample\\Sample;\n\n\n\$a;\n"),
                new CodeSample("<?php\nnamespace Sample\\Sample;\nClass Test{}\n"),
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run after NoUnusedImportsFixer.
     */
    public function getPriority()
    {
        return -20;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(T_NAMESPACE);
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $lastIndex = $tokens->count() - 1;

        for ($index = $lastIndex; $index >= 0; --$index) {
            $token = [REDACTED];

            if (!$token->isGivenKind(T_NAMESPACE)) {
                continue;
            }

            $semicolonIndex = $tokens->getNextTokenOfKind($index, [';', '{', [T_CLOSE_TAG]]);
            $semicolonToken = [REDACTED];

            if (!$semicolonToken->equals(';')) {
                continue;
            }

            $indexToEnsureBlankLineAfter = $this->getIndexToEnsureBlankLineAfter($tokens, $semicolonIndex);
            $indexToEnsureBlankLine = $tokens->getNonEmptySibling($indexToEnsureBlankLineAfter, 1);

            if (null !== $indexToEnsureBlankLine && $tokens[$indexToEnsureBlankLine]->isWhitespace()) {
                $tokens[$indexToEnsureBlankLine] = $this->getTokenToInsert($tokens[$indexToEnsureBlankLine]->getContent(), $indexToEnsureBlankLine === $lastIndex);
            } else {
                $tokens->insertAt($indexToEnsureBlankLineAfter + 1, $this->getTokenToInsert('', $indexToEnsureBlankLineAfter === $lastIndex));
            }
        }
    }

    /**
     * @param int $index
     *
     * @return int
     */
    private function getIndexToEnsureBlankLineAfter(Tokens $tokens, $index)
    {
        $indexToEnsureBlankLine = $index;
        $nextIndex = $tokens->getNonEmptySibling($indexToEnsureBlankLine, 1);

        while (null !== $nextIndex) {
            $token = [REDACTED];

            if ($token->isWhitespace()) {
                if (1 === Preg::match('/\R/', $token->getContent())) {
                    break;
                }
                $nextNextIndex = $tokens->getNonEmptySibling($nextIndex, 1);

                if (!$tokens[$nextNextIndex]->isComment()) {
                    break;
                }
            }

            if (!$token->isWhitespace() && !$token->isComment()) {
                break;
            }

            $indexToEnsureBlankLine = $nextIndex;
            $nextIndex = $tokens->getNonEmptySibling($indexToEnsureBlankLine, 1);
        }

        return $indexToEnsureBlankLine;
    }

    /**
     * @param string $currentContent
     * @param bool   $isLastIndex
     *
     * @return Token
     */
    private function getTokenToInsert($currentContent, $isLastIndex)
    {
        $ending = $this->whitespacesConfig->getLineEnding();

        $emptyLines = $isLastIndex ? $ending : $ending.$ending;
        $indent = 1 === Preg::match('/^.*\R( *)$/s', $currentContent, $matches) ? $matches[1] : '';

        return new Token([T_WHITESPACE, $emptyLines.$indent]);
    }
}
