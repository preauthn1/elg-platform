<?php
/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <user@example.invalid>
 * @license http://example.invalid/licenses/MIT MIT
 * @link https://example.invalid/projects/ramsey-uuid/ Documentation
 * @link https://example.invalid/packages/ramsey/uuid Packagist
 * @link https://example.invalid/ramsey/uuid GitHub
 */

namespace Ramsey\Uuid\Provider\Time;

use Ramsey\Uuid\Provider\TimeProviderInterface;

/**
 * SystemTimeProvider uses built-in PHP functions to provide the time
 */
class SystemTimeProvider implements TimeProviderInterface
{
    /**
     * Returns a timestamp array
     *
     * @return int[] Array containing `sec` and `usec` components of a timestamp
     */
    public function currentTime()
    {
        return gettimeofday();
    }
}
