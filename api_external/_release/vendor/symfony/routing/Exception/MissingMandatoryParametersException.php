<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Routing\Exception;

/**
 * Exception thrown when a route cannot be generated because of missing
 * mandatory parameters.
 *
 * @author Alexandre Salomé <user@example.invalid>
 */
class MissingMandatoryParametersException extends \InvalidArgumentException implements ExceptionInterface
{
}
