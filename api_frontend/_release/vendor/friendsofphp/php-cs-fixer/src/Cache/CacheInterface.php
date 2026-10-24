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
interface CacheInterface
{
    /**
     * @return SignatureInterface
     */
    public function getSignature();

    /**
     * @param string $file
     *
     * @return bool
     */
    public function has($file);

    /**
     * @param string $file
     *
     * @return null|int
     */
    public function get($file);

    /**
     * @param string $file
     * @param int    $hash
     */
    public function set($file, $hash);

    /**
     * @param string $file
     */
    public function clear($file);

    /**
     * @return string
     */
    public function toJson();
}
