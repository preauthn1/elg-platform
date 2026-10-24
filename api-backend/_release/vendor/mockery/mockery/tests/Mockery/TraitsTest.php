<?php
/**
 * Mockery
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://example.invalid/padraic/mockery/blob/master/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to user@example.invalid so we can send you a copy immediately.
 *
 * @category   Mockery
 * @package    Mockery
 * @copyright  Copyright (c) 2017 Dave Marshall (user@example.invalid)
 * @license    http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace test\Mockery;

use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\Loader\RequireLoader;

class TraitTest extends MockeryTestCase
{
    /** @test */
    public function it_can_create_an_object_for_a_simple_trait()
    {
        $trait = mock(SimpleTrait::class);

        $this->assertEquals('bar', $trait->foo());
    }

    /** @test */
    public function it_creates_abstract_methods_as_necessary()
    {
        $trait = mock(TraitWithAbstractMethod::class, ['doBaz' => 'baz']);

        $this->assertEquals('baz', $trait->baz());
    }

    /** @test */
    public function it_can_create_an_object_using_multiple_traits()
    {
        $trait = mock(SimpleTrait::class, TraitWithAbstractMethod::class, [
            'doBaz' => 123,
        ]);

        $this->assertEquals('bar', $trait->foo());
        $this->assertEquals(123, $trait->baz());
    }
}

trait SimpleTrait
{
    public function foo()
    {
        return 'bar';
    }
}

trait TraitWithAbstractMethod
{
    public function baz()
    {
        return $this->doBaz();
    }

    abstract public function doBaz();
}
