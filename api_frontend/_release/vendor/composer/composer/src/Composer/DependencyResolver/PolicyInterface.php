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

namespace Composer\DependencyResolver;

use Composer\Package\PackageInterface;

/**
 * @author Nils Adermann <user@example.invalid>
 */
interface PolicyInterface
{
    public function versionCompare(PackageInterface $a, PackageInterface $b, $operator);

    public function findUpdatePackages(Pool $pool, array $installedMap, PackageInterface $package);

    public function selectPreferredPackages(Pool $pool, array $installedMap, array $literals, $requiredPackage = null);
}
