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

abstract class AbstractVersionConstraint implements VersionConstraint {
    /**
     * @var string
     */
    private $originalValue = '';

    /**
     * @param string $originalValue
     */
    public function __construct($originalValue) {
        $this->originalValue = $originalValue;
    }

    /**
     * @return string
     */
    public function asString() {
        return $this->originalValue;
    }
}
