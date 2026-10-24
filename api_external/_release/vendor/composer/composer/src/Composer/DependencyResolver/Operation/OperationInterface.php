<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <user@example.invalid>
 *     Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver\Operation;

/**
 * Solver operation interface.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
interface OperationInterface
{
    /**
     * Returns job type.
     *
     * @return string
     */
    public function getJobType();

    /**
     * Returns operation reason.
     *
     * @return string
     */
    public function getReason();

    /**
     * Serializes the operation in a human readable format
     *
     * @return string
     */
    public function __toString();
}
