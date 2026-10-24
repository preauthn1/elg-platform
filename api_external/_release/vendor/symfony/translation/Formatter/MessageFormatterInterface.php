<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Formatter;

/**
 * @author Guilherme Blanco <user@example.invalid>
 * @author Abdellatif Ait boudad <user@example.invalid>
 */
interface MessageFormatterInterface
{
    /**
     * Formats a localized message pattern with given arguments.
     *
     * @param string $message    The message (may also be an object that can be cast to string)
     * @param string $locale     The message locale
     * @param array  $parameters An array of parameters for the message
     *
     * @return string
     */
    public function format($message, $locale, array $parameters = []);
}
