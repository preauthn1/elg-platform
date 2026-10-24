<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\Argument\Token;

/**
 * Argument token interface.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
interface TokenInterface
{
    /**
     * Calculates token match score for provided argument.
     *
     * @param $argument
     *
     * @return bool|int
     */
    public function scoreArgument($argument);

    /**
     * Returns true if this token prevents check of other tokens (is last one).
     *
     * @return bool|int
     */
    public function isLast();

    /**
     * Returns string representation for token.
     *
     * @return string
     */
    public function __toString();
}
