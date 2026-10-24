<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Reader;

use Symfony\Component\Translation\MessageCatalogue;

/**
 * TranslationReader reads translation messages from translation files.
 *
 * @author Tobias Nyholm <user@example.invalid>
 */
interface TranslationReaderInterface
{
    /**
     * Reads translation messages from a directory to the catalogue.
     *
     * @param string           $directory
     * @param MessageCatalogue $catalogue
     */
    public function read($directory, MessageCatalogue $catalogue);
}
