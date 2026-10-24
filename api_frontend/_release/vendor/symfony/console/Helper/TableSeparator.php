<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Helper;

/**
 * Marks a row as being a separator.
 *
 * @author Fabien Potencier <user@example.invalid>
 */
class TableSeparator extends TableCell
{
    public function __construct(array $options = [])
    {
        parent::__construct('', $options);
    }
}
