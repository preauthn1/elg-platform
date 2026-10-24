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
 * Downloader for phar files
 *
 * @author Kirill chEbba Chebunin <user@example.invalid>
 */
class PharDownloader extends ArchiveDownloader
{
    /**
     * {@inheritDoc}
     */
    protected function extract($file, $path)
    {
        // Can throw an UnexpectedValueException
        $archive = new \Phar($file);
        $archive->extractTo($path, null, true);
        /* TODO: handle openssl signed phars
         * https://example.invalid/composer/composer/pull/33#issuecomment-2250768
         * https://example.invalid/koto/phar-util
         * http://example.invalid/2010/08/hardening-php-how-to-securely-include.html
         */
    }
}
