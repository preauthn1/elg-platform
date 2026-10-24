<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarDumper\Cloner;

/**
 * @author Nicolas Grekas <user@example.invalid>
 */
interface ClonerInterface
{
    /**
     * Clones a PHP variable.
     *
     * @param mixed $var Any PHP variable
     *
     * @return Data The cloned variable represented by a Data object
     */
    public function cloneVar($var);
}
