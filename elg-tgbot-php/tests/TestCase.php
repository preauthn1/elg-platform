<?php

namespace Tests;

use App\DTOs\BotInfoDTO;
use App\Helpers\CacheKeysHelper;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected string $botId = 'test_bot_id';

    protected string $inviteCode = 'test_invite_code';

    protected function setUp(): void
    {
        parent::setUp();

        // ? Prevent write log to file during testing
        Log::shouldReceive('build')->andReturn(Log::getFacadeRoot());
        Log::shouldReceive('channel')->andReturn(Log::getFacadeRoot());
        Log::shouldReceive('info')->andReturnNull();

        // Set bot infos
        Cache::put(CacheKeysHelper::getBotInfoKey($this->botId), new BotInfoDTO(
            $this->botId,
            'test_bot_code',
            'test_bot_token',
            'test_invite_code'
        ));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    protected function cacheShouldPut(string $key, mixed $value, int $seconds = 600): void
    {
        Cache::shouldReceive('put')
            ->with($key, $value, $seconds)
            ->once();
    }

    protected function cacheShouldGet(string $key, mixed $value): void
    {
        Cache::shouldReceive('get')
            ->with($key)
            ->once()
            ->andReturn($value);
    }

    protected function getTgbotApi_Message_Request(): Request
    {
        $data = [
            'update_id' => 80896233,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => 123456789,
                    'is_bot' => '',
                    'first_name' => 'Helo',
                    'last_name' => 'Wu',
                    'username' => 'helo_terpro',
                    'language_code' => 'zh-hans',
                ],
                'chat' => [
                    'id' => 123456789,
                    'first_name' => 'Helo',
                    'last_name' => 'Wu',
                    'username' => 'helo_terpro',
                    'type' => 'private',
                ],
                'date' => 1725500589,
                'text' => 'asd',
            ],
        ];

        return Request::create('/', 'POST', $data);
    }
}
