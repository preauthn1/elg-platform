<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Finder\Exception;

/**
 * @author Jean-François Simon <user@example.invalid>
 *
 * @deprecated since 3.3, to be removed in 4.0.
 */
interface ExceptionInterface
{
    /**
     * @return \Symfony\Component\Finder\Adapter\AdapterInterface
     */
    public function getAdapter();
}
