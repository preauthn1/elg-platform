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
 * @link http://example.invalid/commands/bitop
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class StringBitOp extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'BITOP';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterArguments(array $arguments)
    {
        if (count($arguments) === 3 && is_array($arguments[2])) {
            list($operation, $destination) = $arguments;
            $arguments = $arguments[2];
            array_unshift($arguments, $operation, $destination);
        }

        return $arguments;
    }
}
