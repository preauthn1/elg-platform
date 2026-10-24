<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpFoundation\File\Exception;

/**
 * Thrown when the access on a file was denied.
 *
 * @author Bernhard Schussek <user@example.invalid>
 */
class AccessDeniedException extends FileException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('The file %s could not be accessed', $path));
    }
}
