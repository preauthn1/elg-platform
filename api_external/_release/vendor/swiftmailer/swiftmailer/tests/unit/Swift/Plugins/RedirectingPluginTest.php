<?php

class Swift_Plugins_RedirectingPluginTest extends \PHPUnit\Framework\TestCase
{
    public function testRecipientCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_RedirectingPlugin('user@example.invalid');
        $this->assertEquals('user@example.invalid', $plugin->getRecipient());
        $plugin->setRecipient('user@example.invalid');
        $this->assertEquals('user@example.invalid', $plugin->getRecipient());
    }

    public function testPluginChangesRecipients()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['user@example.invalid' => 'John Doe'])
            ->setTo($to = [
                'user@example.invalid' => 'Fabien (To)',
                'user@example.invalid' => 'Chris (To)',
            ])
            ->setCc($cc = [
                'user@example.invalid' => 'Fabien (Cc)',
                'user@example.invalid' => 'Chris (Cc)',
            ])
            ->setBcc($bcc = [
                'user@example.invalid' => 'Fabien (Bcc)',
                'user@example.invalid' => 'Chris (Bcc)',
            ])
            ->setBody('...')
        ;

        $plugin = new Swift_Plugins_RedirectingPlugin('user@example.invalid');

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['user@example.invalid' => '']);
        $this->assertEquals($message->getCc(), []);
        $this->assertEquals($message->getBcc(), []);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), $to);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testPluginRespectsUnsetToList()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['user@example.invalid' => 'John Doe'])
            ->setCc($cc = [
                'user@example.invalid' => 'Fabien (Cc)',
                'user@example.invalid' => 'Chris (Cc)',
            ])
            ->setBcc($bcc = [
                'user@example.invalid' => 'Fabien (Bcc)',
                'user@example.invalid' => 'Chris (Bcc)',
            ])
            ->setBody('...')
        ;

        $plugin = new Swift_Plugins_RedirectingPlugin('user@example.invalid');

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['user@example.invalid' => '']);
        $this->assertEquals($message->getCc(), []);
        $this->assertEquals($message->getBcc(), []);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), []);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testPluginRespectsAWhitelistOfPatterns()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['user@example.invalid' => 'John Doe'])
            ->setTo($to = [
                'user@example.invalid' => 'Fabien (To)',
                'user@example.invalid' => 'Chris (To)',
                'user@example.invalid' => 'Lars (To)',
            ])
            ->setCc($cc = [
                'user@example.invalid' => 'Fabien (Cc)',
                'user@example.invalid' => 'Chris (Cc)',
                'user@example.invalid' => 'Lars (Cc)',
            ])
            ->setBcc($bcc = [
                'user@example.invalid' => 'Fabien (Bcc)',
                'user@example.invalid' => 'Chris (Bcc)',
                'user@example.invalid' => 'John (Bcc)',
            ])
            ->setBody('...')
        ;

        $recipient = 'user@example.invalid';
        $patterns = ['/^.*@internal.[a-z]+$/', '/^john-.*$/'];

        $plugin = new Swift_Plugins_RedirectingPlugin($recipient, $patterns);

        $this->assertEquals($recipient, $plugin->getRecipient());
        $this->assertEquals($plugin->getWhitelist(), $patterns);

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['user@example.invalid' => 'Lars (To)', 'user@example.invalid' => null]);
        $this->assertEquals($message->getCc(), ['user@example.invalid' => 'Lars (Cc)']);
        $this->assertEquals($message->getBcc(), ['user@example.invalid' => 'John (Bcc)']);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), $to);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testArrayOfRecipientsCanBeExplicitlyDefined()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['user@example.invalid' => 'John Doe'])
            ->setTo([
            'user@example.invalid' => 'Fabien',
            'user@example.invalid' => 'Chris (To)',
            'user@example.invalid' => 'Lars (To)',
        ])
            ->setCc([
            'user@example.invalid' => 'Fabien',
            'user@example.invalid' => 'Chris (Cc)',
            'user@example.invalid' => 'Lars (Cc)',
        ])
            ->setBcc([
            'user@example.invalid' => 'Fabien',
            'user@example.invalid' => 'Chris (Bcc)',
            'user@example.invalid' => 'John (Bcc)',
        ])
            ->setBody('...')
        ;

        $recipients = ['user@example.invalid', 'user@example.invalid'];
        $patterns = ['/^.*@internal.[a-z]+$/'];

        $plugin = new Swift_Plugins_RedirectingPlugin($recipients, $patterns);

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals(
            $message->getTo(),
            ['user@example.invalid' => 'Fabien', 'user@example.invalid' => 'Lars (To)', 'user@example.invalid' => null]
        );
        $this->assertEquals(
            $message->getCc(),
            ['user@example.invalid' => 'Fabien', 'user@example.invalid' => 'Lars (Cc)']
        );
        $this->assertEquals($message->getBcc(), ['user@example.invalid' => 'Fabien']);
    }

    private function createSendEvent(Swift_Mime_SimpleMessage $message)
    {
        $evt = $this->getMockBuilder('Swift_Events_SendEvent')
                    ->disableOriginalConstructor()
                    ->getMock();
        $evt->expects($this->any())
            ->method('getMessage')
            ->will($this->returnValue($message));

        return $evt;
    }
}
