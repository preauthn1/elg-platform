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

/**
 * Installed array repository.
 *
 * This is used for serving the RootPackage inside an in-memory InstalledRepository
 *
 * @author Jordi Boggiano <user@example.invalid>
 */
class InstalledArrayRepository extends WritableArrayRepository implements InstalledRepositoryInterface
{
}
