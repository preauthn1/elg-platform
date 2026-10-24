<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Command;

/**
 * @link http://example.invalid/commands/sdiffstore
 *
 * @author Daniele Alessandri <user@example.invalid>
 */
class SetDifferenceStore extends SetIntersectionStore
{
    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'SDIFFSTORE';
    }
}
