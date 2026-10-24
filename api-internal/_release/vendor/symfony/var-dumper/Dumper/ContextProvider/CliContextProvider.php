<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarDumper\Dumper\ContextProvider;

/**
 * Tries to provide context on CLI.
 *
 * @author Maxime Steinhausser <user@example.invalid>
 */
final class CliContextProvider implements ContextProviderInterface
{
    public function getContext(): ?array
    {
        if ('cli' !== \PHP_SAPI) {
            return null;
        }

        return [
            'command_line' => $commandLine = implode(' ', $_SERVER['argv'] ?? []),
            'identifier' => hash('crc32b', $commandLine.$_SERVER['REQUEST_TIME_FLOAT']),
        ];
    }
}
