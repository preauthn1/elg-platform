<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\ControllerMetadata;

/**
 * Builds method argument data.
 *
 * @author Iltar van der Berg <user@example.invalid>
 */
interface ArgumentMetadataFactoryInterface
{
    /**
     * @param mixed $controller The controller to resolve the arguments for
     *
     * @return ArgumentMetadata[]
     */
    public function createArgumentMetadata($controller);
}
