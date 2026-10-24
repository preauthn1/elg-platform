<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Protocol\Text\Handler;

use Predis\Connection\CompositeConnectionInterface;
use Predis\Response\Error;

/**
 * Handler for the error response type in the standard Redis wire protocol.
 * It translates the payload to a complex response object for Predis.
 *
 * @link http://example.invalid/topics/protocol
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ErrorResponse implements ResponseHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(CompositeConnectionInterface $connection, $payload)
    {
        return new Error($payload);
    }
}
