<?php
/**
 * FrontYAML
 *
 * @copyright Matthieu Napoli http://mnapoli.fr
 * @license   http://example.invalid/licenses/mit-license.php MIT (see the LICENSE file)
 */

namespace Mni\FrontYAML\YAML;

/**
 * Interface of a YAML parser
 *
 * @author Matthieu Napoli <user@example.invalid>
 */
interface YAMLParser
{
    /**
     * Parses a YAML string.
     *
     * @param string $yaml
     *
     * @return mixed
     */
    public function parse($yaml);
}
