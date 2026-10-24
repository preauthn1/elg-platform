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

use Predis\CommunicationException;

/**
 * Exception used to indentify errors encountered while parsing the Redis wire
 * protocol.
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ProtocolException extends CommunicationException
{
}
