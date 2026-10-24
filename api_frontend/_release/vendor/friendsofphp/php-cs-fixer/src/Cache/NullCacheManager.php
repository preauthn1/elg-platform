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
final class NullCacheManager implements CacheManagerInterface
{
    public function needFixing($file, $fileContent)
    {
        return true;
    }

    public function setFile($file, $fileContent)
    {
    }
}
