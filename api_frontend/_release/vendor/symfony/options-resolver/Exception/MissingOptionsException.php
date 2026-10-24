<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\OptionsResolver\Exception;

/**
 * Exception thrown when a required option is missing.
 *
 * Add the option to the passed options array.
 *
 * @author Bernhard Schussek <user@example.invalid>
 */
class MissingOptionsException extends InvalidArgumentException
{
}
