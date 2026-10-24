<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Protocol;

use Predis\Command\CommandInterface;

/**
 * Defines a pluggable serializer for Redis commands.
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
interface RequestSerializerInterface
{
    /**
     * Serializes a Redis command.
     *
     * @param CommandInterface $command Redis command.
     *
     * @return string
     */
    public function serialize(CommandInterface $command);
}
