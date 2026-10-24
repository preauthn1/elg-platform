<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *     Dariusz Rumiński <user@example.invalid>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Cache;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 *
 * @internal
 */
final class Directory implements DirectoryInterface
{
    /**
     * @var string
     */
    private $directoryName;

    /**
     * @param string $directoryName
     */
    public function __construct($directoryName)
    {
        $this->directoryName = $directoryName;
    }

    public function getRelativePathTo($file)
    {
        $file = $this->normalizePath($file);

        if (
            '' === $this->directoryName
            || 0 !== stripos($file, $this->directoryName.\DIRECTORY_SEPARATOR)
        ) {
            return $file;
        }

        return substr($file, \strlen($this->directoryName) + 1);
    }

    private function normalizePath($path)
    {
        return str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $path);
    }
}
