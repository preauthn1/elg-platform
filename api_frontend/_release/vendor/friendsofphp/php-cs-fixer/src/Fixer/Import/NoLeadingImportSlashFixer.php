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

namespace PhpCsFixer\Fixer\Import;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * @author Carlos Cirello <user@example.invalid>
 */
final class NoLeadingImportSlashFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Remove leading slashes in `use` clauses.',
            [new CodeSample("<?php\nnamespace Foo;\nuse \\Bar;\n")]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run before OrderedImportsFixer.
     * Must run after NoUnusedImportsFixer, SingleImportPerStatementFixer.
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
        return $tokens->isTokenKindFound(T_USE);
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $usesIndexes = $tokensAnalyzer->getImportUseIndexes();

        foreach ($usesIndexes as $idx) {
            $nextTokenIdx = $tokens->getNextMeaningfulToken($idx);
            $nextToken = [REDACTED];

            if ($nextToken->isGivenKind(T_NS_SEPARATOR)) {
                $this->removeLeadingImportSlash($tokens, $nextTokenIdx);
            } elseif ($nextToken->isGivenKind([CT::T_FUNCTION_IMPORT, CT::T_CONST_IMPORT])) {
                $nextTokenIdx = $tokens->getNextMeaningfulToken($nextTokenIdx);
                if ($tokens[$nextTokenIdx]->isGivenKind(T_NS_SEPARATOR)) {
                    $this->removeLeadingImportSlash($tokens, $nextTokenIdx);
                }
            }
        }
    }

    /**
     * @param int $index
     */
    private function removeLeadingImportSlash(Tokens $tokens, $index)
    {
        $previousIndex = $tokens->getPrevNonWhitespace($index);

        if (
            $previousIndex < $index - 1
            || $tokens[$previousIndex]->isComment()
        ) {
            $tokens->clearAt($index);

            return;
        }

        $tokens[$index] = new Token([T_WHITESPACE, ' ']);
    }
}
