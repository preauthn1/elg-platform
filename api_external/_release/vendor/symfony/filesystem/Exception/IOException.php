<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Filesystem\Exception;

/**
 * Exception class thrown when a filesystem operation failure happens.
 *
 * @author Romain Neutron <user@example.invalid>
 * @author Christian Gärtner <user@example.invalid>
 * @author Fabien Potencier <user@example.invalid>
 */
class IOException extends \RuntimeException implements IOExceptionInterface
{
    private $path;

    public function __construct(string $message, int $code = 0, \Exception $previous = null, string $path = null)
    {
        $this->path = $path;

        parent::__construct($message, $code, $previous);
    }

    /**
     * {@inheritdoc}
     */
    public function getPath()
    {
        return $this->path;
    }
}
