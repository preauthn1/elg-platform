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
 * Interface for Api Elements
 */
interface Element
{
    /**
     * Returns the Fqsen of the element.
     *
     * @return Fqsen
     */
    public function getFqsen();

    /**
     * Returns the name of the element.
     *
     * @return string
     */
    public function getName();
}