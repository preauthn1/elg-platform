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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;

/**
 * ChangeReport interface.
 *
 * @author Sascha Egerer <user@example.invalid>
 */
interface ChangeReportInterface
{
    /**
     * Checks for changes to the local copy
     *
     * @param  PackageInterface $package package instance
     * @param  string           $path    package directory
     * @return string|null      changes or null
     */
    public function getLocalChanges(PackageInterface $package, $path);
}
