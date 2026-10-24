<?php
/**
 * phpDocumentor Link Tag Test
 * 
 * PHP version 5.3
 *
 * @author    Ben Selby <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius. (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */

namespace Mpociot\Reflection\DocBlock\Tag;

/**
 * Test class for \phpDocumentor\Reflection\DocBlock\Tag\LinkTag
 *
 * @author    Ben Selby <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius. (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */
class LinkTagTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that the \phpDocumentor\Reflection\DocBlock\Tag\LinkTag can create
     * a link for the @link doc block.
     *
     * @param string $type
     * @param string $content
     * @param string $exContent
     * @param string $exDescription
     * @param string $exLink
     *
     * @covers \phpDocumentor\Reflection\DocBlock\Tag\LinkTag
     * @dataProvider provideDataForConstuctor
     *
     * @return void
     */
    public function testConstructorParesInputsIntoCorrectFields(
        $type,
        $content,
        $exContent,
        $exDescription,
        $exLink
    ) {
        $tag = new LinkTag($type, $content);

        $this->assertEquals($type, $tag->getName());
        $this->assertEquals($exContent, $tag->getContent());
        $this->assertEquals($exDescription, $tag->getDescription());
        $this->assertEquals($exLink, $tag->getLink());
    }

    /**
     * Data provider for testConstructorParesInputsIntoCorrectFields
     *
     * @return array
     */
    public function provideDataForConstuctor()
    {
        // $type, $content, $exContent, $exDescription, $exLink
        return array(
            array(
                'link',
                'http://example.invalid/',
                'http://example.invalid/',
                'http://example.invalid/',
                'http://example.invalid/'
            ),
            array(
                'link',
                'http://example.invalid/ Testing',
                'http://example.invalid/ Testing',
                'Testing',
                'http://example.invalid/'
            ),
            array(
                'link',
                'http://example.invalid/ Testing comments',
                'http://example.invalid/ Testing comments',
                'Testing comments',
                'http://example.invalid/'
            ),
        );
    }
}
