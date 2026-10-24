<?php
/**
 * phpDocumentor See Tag Test
 * 
 * PHP version 5.3
 *
 * @author    Daniel O'Connor <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius. (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */

namespace Mpociot\Reflection\DocBlock\Tag;

/**
 * Test class for \phpDocumentor\Reflection\DocBlock\Tag\SeeTag
 *
 * @author    Daniel O'Connor <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius. (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */
class SeeTagTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that the phpDocumentor_Reflection_DocBlock_Tag_See can create a link
     * for the @see doc block.
     *
     * @param string $type
     * @param string $content
     * @param string $exContent
     * @param string $exReference
     *
     * @covers \phpDocumentor\Reflection\DocBlock\Tag\SeeTag
     * @dataProvider provideDataForConstuctor
     *
     * @return void
     */
    public function testConstructorParesInputsIntoCorrectFields(
        $type,
        $content,
        $exContent,
        $exDescription,
        $exReference
    ) {
        $tag = new SeeTag($type, $content);

        $this->assertEquals($type, $tag->getName());
        $this->assertEquals($exContent, $tag->getContent());
        $this->assertEquals($exDescription, $tag->getDescription());
        $this->assertEquals($exReference, $tag->getReference());
    }

    /**
     * Data provider for testConstructorParesInputsIntoCorrectFields
     *
     * @return array
     */
    public function provideDataForConstuctor()
    {
        // $type, $content, $exContent, $exDescription, $exReference
        return array(
            array(
                'see',
                'Foo::bar()',
                'Foo::bar()',
                '',
                'Foo::bar()'
            ),
            array(
                'see',
                'Foo::bar() Testing',
                'Foo::bar() Testing',
                'Testing',
                'Foo::bar()',
            ),
            array(
                'see',
                'Foo::bar() Testing comments',
                'Foo::bar() Testing comments',
                'Testing comments',
                'Foo::bar()',
            ),
        );
    }
}
