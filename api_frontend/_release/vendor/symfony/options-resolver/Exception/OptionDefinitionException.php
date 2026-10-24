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
 * Thrown when two lazy options have a cyclic dependency.
 *
 * @author Bernhard Schussek <user@example.invalid>
 */
class OptionDefinitionException extends \LogicException implements ExceptionInterface
{
}
