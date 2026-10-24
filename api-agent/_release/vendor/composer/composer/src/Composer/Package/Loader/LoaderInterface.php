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

namespace Composer\Package\Loader;

/**
 * Defines a loader that takes an array to create package instances
 *
 * @author Jordi Boggiano <user@example.invalid>
 */
interface LoaderInterface
{
    /**
     * Converts a package from an array to a real instance
     *
     * @param  array                              $package Package config
     * @param  string                             $class   Package class to use
     * @return \Composer\Package\PackageInterface
     */
    public function load(array $package, $class = 'Composer\Package\CompletePackage');
}
