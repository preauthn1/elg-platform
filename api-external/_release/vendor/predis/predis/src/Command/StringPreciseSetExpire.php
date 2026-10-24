<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Command;

/**
 * @link http://example.invalid/commands/psetex
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class StringPreciseSetExpire extends StringSetExpire
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'PSETEX';
    }
}
