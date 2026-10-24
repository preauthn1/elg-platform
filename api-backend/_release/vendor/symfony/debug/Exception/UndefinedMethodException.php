<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Debug\Exception;

/**
 * Undefined Method Exception.
 *
 * @author Grégoire Pineau <user@example.invalid>
 */
class UndefinedMethodException extends FatalErrorException
{
    public function __construct($message, \ErrorException $previous)
    {
        parent::__construct(
            $message,
            $previous->getCode(),
            $previous->getSeverity(),
            $previous->getFile(),
            $previous->getLine(),
            null,
            true,
            null,
            $previous->getPrevious()
        );
        $this->setTrace($previous->getTrace());
    }
}
