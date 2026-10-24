<?php

/*
 * This file is part of Collision.
 *
 * (c) Nuno Maduro <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NunoMaduro\Collision\Contracts;

use Symfony\Component\Console\Output\OutputInterface;
use Whoops\Handler\HandlerInterface;

/**
 * This is an Collision Handler contract.
 *
 * @author Nuno Maduro <user@example.invalid>
 */
interface Handler extends HandlerInterface
{
    /**
     * Sets the output.
     *
     * @return \NunoMaduro\Collision\Contracts\Handler
     */
    public function setOutput(OutputInterface $output): Handler;

    /**
     * Returns the writer.
     *
     * @return \NunoMaduro\Collision\Contracts\Writer
     */
    public function getWriter(): Writer;
}
