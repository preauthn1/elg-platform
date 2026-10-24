<?php

/**
 * This file is part of Collision.
 *
 * (c) Nuno Maduro <user@example.invalid>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace NunoMaduro\Collision\Contracts;

/**
 * This is the Collision Highlighter contract.
 *
 * @author Nuno Maduro <user@example.invalid>
 */
interface Highlighter
{
    /**
     * Highlights the provided content.
     */
    public function highlight(string $content, int $line): string;
}
