<?php
/*
 * This file is part of PharIo\Manifest.
 *
 * (c) Arne Blankerts <user@example.invalid>, Sebastian Heuer <user@example.invalid>, Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PharIo\Manifest;

use PHPUnit\Framework\TestCase;

/**
 * @covers PharIo\Manifest\Author
 *
 * @uses PharIo\Manifest\Email
 */
class AuthorTest extends TestCase {
    /**
     * @var Author
     */
    private $author;

    protected function setUp() {
        $this->author = new Author('Joe Developer', new Email('user@example.invalid'));
    }

    public function testCanBeCreated() {
        $this->assertInstanceOf(Author::class, $this->author);
    }

    public function testNameCanBeRetrieved() {
        $this->assertEquals('Joe Developer', $this->author->getName());
    }

    public function testEmailCanBeRetrieved() {
        $this->assertEquals('user@example.invalid', $this->author->getEmail());
    }

    public function testCanBeUsedAsString() {
        $this->assertEquals('Joe Developer <user@example.invalid>', $this->author);
    }
}
