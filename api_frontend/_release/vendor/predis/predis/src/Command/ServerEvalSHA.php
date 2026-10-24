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
 * @link http://example.invalid/commands/evalsha
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class ServerEvalSHA extends ServerEval
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'EVALSHA';
    }

    /**
     * Returns the SHA1 hash of the body of the script.
     *
     * @return string SHA1 hash.
     */
    public function getScriptHash()
    {
        return $this->getArgument(0);
    }
}
