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

namespace PhpCsFixer\Cache;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 */
interface DirectoryInterface
{
    /**
     * @param string $file
     *
     * @return string
     */
    public function getRelativePathTo($file);
}
