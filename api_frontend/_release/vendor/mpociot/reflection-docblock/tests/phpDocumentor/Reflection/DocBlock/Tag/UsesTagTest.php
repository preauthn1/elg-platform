<?php
/**
 * phpDocumentor Uses Tag Test
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
 * Test class for \phpDocumentor\Reflection\DocBlock\Tag\UsesTag
 *
 * @author    Daniel O'Connor <user@example.invalid>
 * @copyright 2010-2011 Mike van Riel / Naenius. (http://example.invalid)
 * @license   http://example.invalid/licenses/mit-license.php MIT
 * @link      http://example.invalid
 */
class UsesTagTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that the \phpDocumentor\Reflection\DocBlock\Tag\UsesTag can create
     * a link for the @uses doc block.
     *
     * @param string $type
     * @param string $content
     * @param string $exContent
     * @param string $exReference
     *
     * @covers \phpDocumentor\Reflection\DocBlock\Tag\UsesTag
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
        $tag = new UsesTag($type, $content);

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
                'uses',
                'Foo::bar()',
                'Foo::bar()',
                '',
                'Foo::bar()'
            ),
            array(
                'uses',
                'Foo::bar() Testing',
                'Foo::bar() Testing',
                'Testing',
                'Foo::bar()',
            ),
            array(
                'uses',
                'Foo::bar() Testing comments',
                'Foo::bar() Testing comments',
                'Testing comments',
                'Foo::bar()',
            ),
        );
    }
}
