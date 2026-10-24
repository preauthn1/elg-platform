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
 * @link http://example.invalid/commands/bgrewriteaof
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ServerBackgroundRewriteAOF extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'BGREWRITEAOF';
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponse($data)
    {
        return $data == 'Background append only file rewriting started';
    }
}
