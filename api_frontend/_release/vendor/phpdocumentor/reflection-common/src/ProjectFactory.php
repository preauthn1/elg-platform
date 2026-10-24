<?php
/**
 * phpDocumentor
 *
 * PHP Version 5.5
 *
 * @copyright 2010-2015 Mike van Riel / Naenius (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */
namespace phpDocumentor\Reflection;

/**
 * Interface for project factories. A project factory shall convert a set of files
 * into an object implementing the Project interface.
 */
interface ProjectFactory
{
    /**
     * Creates a project from the set of files.
     *
     * @param string $name
     * @param File[] $files
     * @return Project
     */
    public function create($name, array $files);
}
