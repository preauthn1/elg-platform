<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler\FingersCrossed;

/**
 * Interface for activation strategies for the FingersCrossedHandler.
 *
 * @author Johannes M. Schmitt <user@example.invalid>
 */
interface ActivationStrategyInterface
{
    /**
     * Returns whether the given record activates the handler.
     */
    public function isHandlerActivated(array $record): bool;
}
