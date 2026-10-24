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
 * @link http://example.invalid/commands/sadd
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class SetAdd extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'SADD';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterArguments(array $arguments)
    {
        return self::normalizeVariadic($arguments);
    }
}
