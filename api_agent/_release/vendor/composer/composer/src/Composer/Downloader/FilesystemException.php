<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <user@example.invalid>
 *     Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

/**
 * Exception thrown when issues exist on local filesystem
 *
 * @author Javier Spagnoletti <user@example.invalid>
 */
class FilesystemException extends \Exception
{
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        parent::__construct("Filesystem exception: \n".$message, $code, $previous);
    }
}
