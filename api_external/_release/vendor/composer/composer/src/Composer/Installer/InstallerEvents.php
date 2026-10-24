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

/**
 * The Installer Events.
 *
 * @author François Pluchino <user@example.invalid>
 */
class InstallerEvents
{
    /**
     * The PRE_DEPENDENCIES_SOLVING event occurs as a installer begins
     * resolve operations.
     *
     * The event listener method receives a
     * Composer\Installer\InstallerEvent instance.
     *
     * @var string
     */
    const PRE_DEPENDENCIES_SOLVING = 'pre-dependencies-solving';

    /**
     * The POST_DEPENDENCIES_SOLVING event occurs as a installer after
     * resolve operations.
     *
     * The event listener method receives a
     * Composer\Installer\InstallerEvent instance.
     *
     * @var string
     */
    const POST_DEPENDENCIES_SOLVING = 'post-dependencies-solving';
}
