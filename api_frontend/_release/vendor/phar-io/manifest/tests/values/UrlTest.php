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
 * @covers PharIo\Manifest\Url
 */
class UrlTest extends TestCase {
    public function testCanBeCreatedForValidUrl() {
        $this->assertInstanceOf(Url::class, new Url('https://example.invalid/'));
    }

    public function testCanBeUsedAsString() {
        $this->assertEquals('https://example.invalid/', new Url('https://example.invalid/'));
    }

    /**
     * @covers PharIo\Manifest\InvalidUrlException
     */
    public function testCannotBeCreatedForInvalidUrl() {
        $this->expectException(InvalidUrlException::class);

        new Url('invalid');
    }
}
