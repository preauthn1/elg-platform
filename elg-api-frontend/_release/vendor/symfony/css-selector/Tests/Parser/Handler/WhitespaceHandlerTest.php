<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\CssSelector\Tests\Parser\Handler;

use Symfony\Component\CssSelector\Parser\Handler\WhitespaceHandler;
use Symfony\Component\CssSelector\Parser\Token;

class WhitespaceHandlerTest extends AbstractHandlerTest
{
    public function getHandleValueTestData()
    {
        return [
            [' ', new Token(Token:[REDACTED], ' ', 0), ''],
            ["\n", new Token(Token:[REDACTED], "\n", 0), ''],
            ["\t", new Token(Token:[REDACTED], "\t", 0), ''],

            [' foo', new Token(Token:[REDACTED], ' ', 0), 'foo'],
            [' .foo', new Token(Token:[REDACTED], ' ', 0), '.foo'],
        ];
    }

    public function getDontHandleValueTestData()
    {
        return [
            ['>'],
            ['1'],
            ['a'],
        ];
    }

    protected function generateHandler()
    {
        return new WhitespaceHandler();
    }
}
