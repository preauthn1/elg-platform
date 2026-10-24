<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\DataCollector;

/**
 * LateDataCollectorInterface.
 *
 * @author Fabien Potencier <user@example.invalid>
 */
interface LateDataCollectorInterface
{
    /**
     * Collects data as late as possible.
     */
    public function lateCollect();
}
