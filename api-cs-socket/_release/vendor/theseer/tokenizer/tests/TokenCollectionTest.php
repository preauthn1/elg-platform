<?php declare(strict_types = 1);
namespace TheSeer\Tokenizer;

use PHPUnit\Framework\TestCase;

/**
 * @covers \TheSeer\Tokenizer\TokenCollection
 */
class TokenCollectionTest extends TestCase {

    /** @var  TokenCollection */
    private $collection;

    protected function setUp() {
        $this->collection = new TokenCollection();
    }

    public function testCollectionIsInitiallyEmpty() {
        $this->assertCount(0, $this->collection);
    }

    public function testTokenCanBeAddedToCollection() {
        $token = [REDACTED];
        $this->collection->addToken($token);

        $this->assertCount(1, $this->collection);
        $this->assertSame($token, $this->collection[0]);
    }

    public function testCanIterateOverTokens() {
        $token = [REDACTED];
        $this->collection->addToken($token);
        $this->collection->addToken($token);

        foreach($this->collection as $position => $current) {
            $this->assertInternalType('integer', $position);
            $this->assertSame($token, $current);
        }
    }

    public function testOffsetCanBeUnset() {
        $token = [REDACTED];
        $this->collection->addToken($token);

        $this->assertCount(1, $this->collection);
        unset($this->collection[0]);
        $this->assertCount(0, $this->collection);
    }

    public function testTokenCanBeSetViaOffsetPosition() {
        $token = [REDACTED];
        $this->collection[0] = $token;
        $this->assertCount(1, $this->collection);
        $this->assertSame($token, $this->collection[0]);
    }

    public function testTryingToUseNonIntegerOffsetThrowsException() {
        $this->expectException(TokenCollectionException::class);
        $this->collection['foo'] = $this->createMock(Token:[REDACTED];
    }

    public function testTryingToSetNonTokenAtOffsetThrowsException() {
        $this->expectException(TokenCollectionException::class);
        $this->collection[0] = 'abc';
    }

    public function testTryingToGetTokenAtNonExistingOffsetThrowsException() {
        $this->expectException(TokenCollectionException::class);
        $x = $this->collection[3];
    }

}
