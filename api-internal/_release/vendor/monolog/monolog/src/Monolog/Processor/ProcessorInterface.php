<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor;

/**
 * An optional interface to allow labelling Monolog processors.
 *
 * @author Nicolas Grekas <user@example.invalid>
 */
interface ProcessorInterface
{
    /**
     * @return array The processed record
     */
    public function __invoke(array $record);
}
