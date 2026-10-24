<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Replication;

use Predis\CommunicationException;

/**
 * Exception class that identifies a role mismatch when connecting to node
 * managed by redis-sentinel.
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class RoleException extends CommunicationException
{
}
