<?php

/*
 * This file is part of Collision.
 *
 * (c) Nuno Maduro <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NunoMaduro\Collision\Contracts;

/**
 * This is an Collision Argument Formatter contract.
 *
 * @author Nuno Maduro <user@example.invalid>
 */
interface ArgumentFormatter
{
    /**
     * Formats the provided array of arguments into
     * an understandable description.
     */
    public function format(array $arguments, bool $recursive = true): string;
}
