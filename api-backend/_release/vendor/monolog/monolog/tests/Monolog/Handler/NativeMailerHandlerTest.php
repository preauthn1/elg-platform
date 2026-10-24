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
use InvalidArgumentException;

function mail($to, $subject, $message, $additional_headers = null, $additional_parameters = null)
{
    $GLOBALS['mail'][] = func_get_args();
}

class NativeMailerHandlerTest extends TestCase
{
    protected function setUp()
    {
        $GLOBALS['mail'] = array();
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorHeaderInjection()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'dear victim', "user@example.invalid\r\nFrom: user@example.invalid");
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetterHeaderInjection()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'dear victim', 'user@example.invalid');
        $mailer->addHeader("Content-Type: text/html\r\nFrom: user@example.invalid");
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetterArrayHeaderInjection()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'dear victim', 'user@example.invalid');
        $mailer->addHeader(array("Content-Type: text/html\r\nFrom: user@example.invalid"));
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetterContentTypeInjection()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'dear victim', 'user@example.invalid');
        $mailer->setContentType("text/html\r\nFrom: user@example.invalid");
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetterEncodingInjection()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'dear victim', 'user@example.invalid');
        $mailer->setEncoding("utf-8\r\nFrom: user@example.invalid");
    }

    public function testSend()
    {
        $to = 'user@example.invalid';
        $subject = 'dear victim';
        $from = 'user@example.invalid';

        $mailer = new NativeMailerHandler($to, $subject, $from);
        $mailer->handleBatch(array());

        // batch is empty, nothing sent
        $this->assertEmpty($GLOBALS['mail']);

        // non-empty batch
        $mailer->handle($this->getRecord(Logger::ERROR, "Foo\nBar\r\n\r\nBaz"));
        $this->assertNotEmpty($GLOBALS['mail']);
        $this->assertInternalType('array', $GLOBALS['mail']);
        $this->assertArrayHasKey('0', $GLOBALS['mail']);
        $params = $GLOBALS['mail'][0];
        $this->assertCount(5, $params);
        $this->assertSame($to, $params[0]);
        $this->assertSame($subject, $params[1]);
        $this->assertStringEndsWith(" test.ERROR: Foo Bar  Baz [] []\n", $params[2]);
        $this->assertSame("From: $from\r\nContent-type: text/plain; charset=utf-8\r\n", $params[3]);
        $this->assertSame('', $params[4]);
    }

    public function testMessageSubjectFormatting()
    {
        $mailer = new NativeMailerHandler('user@example.invalid', 'Alert: %level_name% %message%', 'user@example.invalid');
        $mailer->handle($this->getRecord(Logger::ERROR, "Foo\nBar\r\n\r\nBaz"));
        $this->assertNotEmpty($GLOBALS['mail']);
        $this->assertInternalType('array', $GLOBALS['mail']);
        $this->assertArrayHasKey('0', $GLOBALS['mail']);
        $params = $GLOBALS['mail'][0];
        $this->assertCount(5, $params);
        $this->assertSame('Alert: ERROR Foo Bar  Baz', $params[1]);
    }
}
