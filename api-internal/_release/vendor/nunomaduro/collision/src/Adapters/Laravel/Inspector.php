<?php

/**
 * This file is part of Collision.
 *
 * (c) Nuno Maduro <user@example.invalid>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace NunoMaduro\Collision\Adapters\Laravel;

use Whoops\Exception\Inspector as BaseInspector;

/**
 * This is an Collision Laravel Adapter Inspector implementation.
 *
 * @author Nuno Maduro <user@example.invalid>
 */
class Inspector extends BaseInspector
{
    /**
     * {@inheritdoc}
     */
    protected function getTrace($e)
    {
        return $e->getTrace();
    }
}
