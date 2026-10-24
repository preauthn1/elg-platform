<?php

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\TestCase;
use Monolog\Logger;

/**
 * @author Haralan Dobrev <user@example.invalid>
 * @see    https://example.invalid/apps/A0F81R8ET-slackbot
 * @coversDefaultClass Monolog\Handler\SlackbotHandler
 */
class SlackbotHandlerTest extends TestCase
{
    /**
     * @covers ::__construct
     */
    public function testConstructorMinimal()
    {
        $handler = new SlackbotHandler('test-team', 'test-token', 'test-channel');
        $this->assertInstanceOf('Monolog\Handler\AbstractProcessingHandler', $handler);
    }

    /**
     * @covers ::__construct
     */
    public function testConstructorFull()
    {
        $handler = new SlackbotHandler(
            'test-team',
            'test-token',
            'test-channel',
            Logger::DEBUG,
            false
        );
        $this->assertInstanceOf('Monolog\Handler\AbstractProcessingHandler', $handler);
    }
}
