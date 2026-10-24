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

use Composer\Package\PackageInterface;

/**
 * Abstract solver operation class.
 *
 * @author Konstantin Kudryashov <user@example.invalid>
 */
abstract class SolverOperation implements OperationInterface
{
    protected $reason;

    /**
     * Initializes operation.
     *
     * @param string $reason operation reason
     */
    public function __construct($reason = null)
    {
        $this->reason = $reason;
    }

    /**
     * Returns operation reason.
     *
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    protected function formatVersion(PackageInterface $package)
    {
        return $package->getFullPrettyVersion();
    }
}
