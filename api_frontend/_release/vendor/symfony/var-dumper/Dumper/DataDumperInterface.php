<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarDumper\Dumper;

use Symfony\Component\VarDumper\Cloner\Data;

/**
 * DataDumperInterface for dumping Data objects.
 *
 * @author Nicolas Grekas <user@example.invalid>
 */
interface DataDumperInterface
{
    public function dump(Data $data);
}
