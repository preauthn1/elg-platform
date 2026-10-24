<?php

namespace Tests\Unit;

use App\DTOs\TgBotApiMessageParameterDTO;
use App\DTOs\TgWebhookDataDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Singletons\TgBotApiCallService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class TgBotApiCallServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->getTgbotApi_Message_Request();
        $this->app->instance(TgWebhookDataDTO::class, TgWebhookDataDTO::fromRequest($this->botId, $request));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_text_only()
    {
        $mockClient = $this->createMock(Client::class);
        $mockResponse = new Response(200, [], json_encode(['result' => ['message_id' => 123]]));

        $mockClient->method('post')
            ->willReturn($mockResponse);

        $service = new TgBotApiCallService($this->botId, 'test_chat_id');
        $service->setClient($mockClient);

        $messageId = $service->sendTextOnly('Test message');

        $this->assertEquals(123, $messageId);
    }

    public function test_send_message()
    {
        $mockClient = $this->createMock(Client::class);
        $mockResponse = new Response(200, [], json_encode(['result' => ['message_id' => 456]]));

        $mockClient->method('post')
            ->willReturn($mockResponse);

        $service = new TgBotApiCallService($this->botId, 'test_chat_id');
        $service->setClient($mockClient);

        $dto = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: 'Test message'
        );

        $messageId = $service->send($dto);

        $this->assertEquals(456, $messageId);
    }

    public function test_send_animation()
    {
        $mockClient = $this->createMock(Client::class);
        $mockResponse = new Response(200, [], json_encode(['result' => ['message_id' => 789]]));

        $mockClient->method('post')
            ->willReturn($mockResponse);

        $service = new TgBotApiCallService($this->botId, 'test_chat_id');
        $service->setClient($mockClient);

        $dto = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::ANIMATION,
            content: 'Test animation',
            mediaUrl: 'http://example.invalid/animation.gif'
        );

        $messageId = $service->send($dto);

        $this->assertEquals(789, $messageId);
    }
}
