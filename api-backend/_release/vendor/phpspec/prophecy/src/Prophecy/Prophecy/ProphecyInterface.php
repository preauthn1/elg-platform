<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\Prophecy;

/**
 * Core Prophecy interface.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
interface ProphecyInterface
{
    /**
     * Reveals prophecy object (double) .
     *
     * @return object
     */
    public function reveal();
}
