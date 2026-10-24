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

namespace Composer\Installer;

use Composer\Package\PackageInterface;

/**
 * Interface for the package installation manager that handle binary installation.
 *
 * @author Jordi Boggiano <user@example.invalid>
 */
interface BinaryPresenceInterface
{
    /**
     * Make sure binaries are installed for a given package.
     *
     * @param PackageInterface $package package instance
     */
    public function ensureBinariesPresence(PackageInterface $package);
}
