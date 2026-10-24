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
 * @link http://example.invalid/commands/slowlog
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ServerSlowlog extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'SLOWLOG';
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponse($data)
    {
        if (is_array($data)) {
            $log = array();

            foreach ($data as $index => $entry) {
                $log[$index] = array(
                    'id' => $entry[0],
                    'timestamp' => $entry[1],
                    'duration' => $entry[2],
                    'command' => $entry[3],
                );
            }

            return $log;
        }

        return $data;
    }
}
