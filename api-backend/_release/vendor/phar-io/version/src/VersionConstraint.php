<?php
/*
 * This file is part of PharIo\Version.
 *
 * (c) Arne Blankerts <user@example.invalid>, Sebastian Heuer <user@example.invalid>, Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PharIo\Version;

interface VersionConstraint {
    /**
     * @param Version $version
     *
     * @return bool
     */
    public function complies(Version $version);

    /**
     * @return string
     */
    public function asString();

}
