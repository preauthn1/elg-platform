<?php
/**
 * phpDocumentor
 *
 * PHP Version 5.3
 *
 * @author    Ben Selby <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */

namespace Barryvdh\Reflection\DocBlock\Tag;

use Barryvdh\Reflection\DocBlock\Tag;

/**
 * Reflection class for a @link tag in a Docblock.
 *
 * @author  Ben Selby <user@example.invalid>
 * @license http://example.invalid/licenses/mit-license.php MIT
 * @link    http://example.invalid
 */
class LinkTag extends Tag
{
    /** @var string */
    protected $link = '';

    /**
     * {@inheritdoc}
     */
    public function getContent()
    {
        if (null === $this->content) {
            $this->content = "{$this->link} {$this->description}";
        }

        return $this->content;
    }

    /**
     * {@inheritdoc}
     */
    public function setContent($content)
    {
        parent::setContent($content);
        $parts = preg_split('/\s+/Su', $this->description, 2);

        $this->link = $parts[0];

        $this->setDescription(isset($parts[1]) ? $parts[1] : $parts[0]);

        $this->content = $content;
        return $this;
    }

    /**
    * Gets the link
    *
    * @return string
    */
    public function getLink()
    {
        return $this->link;
    }

    /**
    * Sets the link
    *
    * @param string $link The link
    *
    * @return $this
    */
    public function setLink($link)
    {
        $this->link = $link;

        $this->content = null;
        return $this;
    }
}
