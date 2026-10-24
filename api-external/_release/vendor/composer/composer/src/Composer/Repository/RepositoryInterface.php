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

namespace Composer\Repository;

use Composer\Package\PackageInterface;

/**
 * Repository interface.
 *
 * @author Nils Adermann <user@example.invalid>
 * @author Konstantin Kudryashov <user@example.invalid>
 * @author Jordi Boggiano <user@example.invalid>
 */
interface RepositoryInterface extends \Countable
{
    const SEARCH_FULLTEXT = 0;
    const SEARCH_NAME = 1;

    /**
     * Checks if specified package registered (installed).
     *
     * @param PackageInterface $package package instance
     *
     * @return bool
     */
    public function hasPackage(PackageInterface $package);

    /**
     * Searches for the first match of a package by name and version.
     *
     * @param string                                                 $name       package name
     * @param string|\Composer\Semver\Constraint\ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return PackageInterface|null
     */
    public function findPackage($name, $constraint);

    /**
     * Searches for all packages matching a name and optionally a version.
     *
     * @param string                                                 $name       package name
     * @param string|\Composer\Semver\Constraint\ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return PackageInterface[]
     */
    public function findPackages($name, $constraint = null);

    /**
     * Returns list of registered packages.
     *
     * @return PackageInterface[]
     */
    public function getPackages();

    /**
     * Searches the repository for packages containing the query
     *
     * @param string $query search query
     * @param int    $mode  a set of SEARCH_* constants to search on, implementations should do a best effort only
     *
     * @return array[] an array of array('name' => '...', 'description' => '...')
     */
    public function search($query, $mode = 0);
}
