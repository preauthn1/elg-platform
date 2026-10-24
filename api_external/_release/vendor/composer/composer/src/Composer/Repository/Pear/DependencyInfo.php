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

namespace Composer\Repository\Pear;

/**
 * PEAR package release dependency info
 *
 * @author Alexey Prilipko <user@example.invalid>
 */
class DependencyInfo
{
    private $requires;
    private $optionals;

    /**
     * @param DependencyConstraint[] $requires  list of requires/conflicts/replaces
     * @param array                  $optionals [groupName => DependencyConstraint[]] list of optional groups
     */
    public function __construct($requires, $optionals)
    {
        $this->requires = $requires;
        $this->optionals = $optionals;
    }

    /**
     * @return DependencyConstraint[] list of requires/conflicts/replaces
     */
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * @return array [groupName => DependencyConstraint[]] list of optional groups
     */
    public function getOptionals()
    {
        return $this->optionals;
    }
}
