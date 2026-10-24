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
 * @covers PharIo\Manifest\Email
 */
class EmailTest extends TestCase {
    public function testCanBeCreatedForValidEmail() {
        $this->assertInstanceOf(Email::class, new Email('user@example.invalid'));
    }

    public function testCanBeUsedAsString() {
        $this->assertEquals('user@example.invalid', new Email('user@example.invalid'));
    }

    /**
     * @covers PharIo\Manifest\InvalidEmailException
     */
    public function testCannotBeCreatedForInvalidEmail() {
        $this->expectException(InvalidEmailException::class);

        new Email('invalid');
    }
}
