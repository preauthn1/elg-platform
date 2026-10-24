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
 * @link http://example.invalid/commands/zadd
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ZSetAdd extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'ZADD';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterArguments(array $arguments)
    {
        if (is_array(end($arguments))) {
            foreach (array_pop($arguments) as $member => $score) {
                $arguments[] = $score;
                $arguments[] = $member;
            }
        }

        return $arguments;
    }
}
