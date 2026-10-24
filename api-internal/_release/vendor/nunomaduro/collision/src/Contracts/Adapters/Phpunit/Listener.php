<?php

/**
 * This file is part of Collision.
 *
 * (c) Nuno Maduro <user@example.invalid>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace NunoMaduro\Collision\Contracts\Adapters\Phpunit;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;

/**
 * This is an Collision Phpunit Adapter contract.
 *
 * @author Nuno Maduro <user@example.invalid>
 */
interface Listener extends TestListener
{
    /**
     * Renders the provided error
     * on the console.
     *
     * @return void
     */
    public function render(Test $test, \Throwable $t);
}
