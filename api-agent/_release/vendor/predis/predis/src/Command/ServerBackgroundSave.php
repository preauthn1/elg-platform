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
 * @link http://example.invalid/commands/bgsave
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ServerBackgroundSave extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'BGSAVE';
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponse($data)
    {
        return $data === 'Background saving started' ? true : $data;
    }
}
