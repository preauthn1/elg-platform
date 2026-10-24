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
 * @link http://example.invalid/commands/migrate
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class KeyMigrate extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'MIGRATE';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterArguments(array $arguments)
    {
        if (is_array(end($arguments))) {
            foreach (array_pop($arguments) as $modifier => $value) {
                $modifier = strtoupper($modifier);

                if ($modifier === 'COPY' && $value == true) {
                    $arguments[] = $modifier;
                }

                if ($modifier === 'REPLACE' && $value == true) {
                    $arguments[] = $modifier;
                }
            }
        }

        return $arguments;
    }
}
