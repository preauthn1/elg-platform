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

class SpecificMajorAndMinorVersionConstraint extends AbstractVersionConstraint {
    /**
     * @var int
     */
    private $major = 0;

    /**
     * @var int
     */
    private $minor = 0;

    /**
     * @param string $originalValue
     * @param int $major
     * @param int $minor
     */
    public function __construct($originalValue, $major, $minor) {
        parent::__construct($originalValue);

        $this->major = $major;
        $this->minor = $minor;
    }

    /**
     * @param Version $version
     *
     * @return bool
     */
    public function complies(Version $version) {
        if ($version->getMajor()->getValue() != $this->major) {
            return false;
        }

        return $version->getMinor()->getValue() == $this->minor;
    }
}
