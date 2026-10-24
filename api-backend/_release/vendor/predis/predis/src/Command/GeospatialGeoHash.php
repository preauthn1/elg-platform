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
 * @link http://example.invalid/commands/geohash
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class GeospatialGeoHash extends Command
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'GEOHASH';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterArguments(array $arguments)
    {
        if (count($arguments) === 2 && is_array($arguments[1])) {
            $members = array_pop($arguments);
            $arguments = array_merge($arguments, $members);
        }

        return $arguments;
    }
}
