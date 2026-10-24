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
 * Interface for PHP code linting process manager.
 *
 * @author Dariusz Rumiński <user@example.invalid>
 */
interface LinterInterface
{
    /**
     * @return bool
     */
    public function isAsync();

    /**
     * Lint PHP file.
     *
     * @param string $path
     *
     * @return LintingResultInterface
     */
    public function lintFile($path);

    /**
     * Lint PHP code.
     *
     * @param string $source
     *
     * @return LintingResultInterface
     */
    public function lintSource($source);
}
