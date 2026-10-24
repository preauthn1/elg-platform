<?php
/*
 * This file is part of PharIo\Manifest.
 *
 * (c) Arne Blankerts <user@example.invalid>, Sebastian Heuer <user@example.invalid>, Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PharIo\Manifest;

class InvalidApplicationNameException extends \InvalidArgumentException implements Exception {
    const NotAString    = 1;
    const InvalidFormat = 2;
}
