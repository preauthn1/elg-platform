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
 * @author Andreas Möller <user@example.invalid>
 *
 * @internal
 */
interface FileHandlerInterface
{
    /**
     * @return string
     */
    public function getFile();

    /**
     * @return null|CacheInterface
     */
    public function read();

    public function write(CacheInterface $cache);
}
