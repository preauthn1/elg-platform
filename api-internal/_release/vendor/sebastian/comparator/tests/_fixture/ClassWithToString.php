<?php
/*
 * This file is part of sebastian/comparator.
 *
 * (c) Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Comparator;

class ClassWithToString
{
    public function __toString()
    {
        return 'string representation';
    }
}
