<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Profile;

/**
 * Server profile for the current unstable version of Redis.
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class RedisUnstable extends RedisVersion320
{
    /**
     * {@inheritdoc}
     */
    public function getVersion()
    {
        return '3.2';
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedCommands()
    {
        return array_merge(parent::getSupportedCommands(), array(
            // EMPTY
        ));
    }
}
