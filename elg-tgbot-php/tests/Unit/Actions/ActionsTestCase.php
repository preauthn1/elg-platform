<?php

namespace Tests\Unit\Actions;

use App\DTOs\HomepageInfoDTO;
use App\DTOs\PlatformWalletBalanceDTO;
use App\Singletons\PlatformApiCallService;
use App\Singletons\TgBotApiCallService;
use Illuminate\Http\Request;
use Tests\TestCase;

class ActionsTestCase extends TestCase
{
    protected $username = 'helo_terpro';

    protected $mobile = '+123456789';

    protected $tgBotApiCallService;

    protected $platformApiCallService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgBotApiCallService = $this->createMock(TgBotApiCallService::class);
        $this->platformApiCallService = $this->createMock(PlatformApiCallService::class);

        $this->app->instance(TgBotApiCallService::class, $this->tgBotApiCallService);
        $this->app->instance(PlatformApiCallService::class, $this->platformApiCallService);
    }

    protected function mockHomepageInfoRequest()
    {
        $this->platformApiCallService->method('getHomepageInfo')->willReturn(
            new HomepageInfoDTO([
                'event_link' => 'https://example.invalid/event',
                'withdrawal_link' => 'https://example.invalid/withdrawal',
                'cs_link' => 'https://example.invalid/cs',
                'wallet_link' => 'https://example.invalid/wallet',
                'launch_link' => 'https://example.invalid/launch',

                'start_cover_media' => [
                    'type' => 'photo',
                    'url' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png',
                ],
                'restart_cover_media' => [
                    'type' => 'animation',
                    'url' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png',
                ],
            ])
        );
    }

    protected function mockWalletBalanceRequest()
    {
        $this->platformApiCallService->method('getWalletBalance')->willReturn(
            new PlatformWalletBalanceDTO([
                'member_id' => 48722,
                'wallet' => [
                    [
                        'id' => 1,
                        'name' => 'Cash',
                        'balance' => '123.45',
                    ],
                    [
                        'id' => 2,
                        'name' => 'Game',
                        'balance' => '234.56',
                    ],
                    [
                        'id' => 1002,
                        'name' => 'Rolling Chip',
                        'balance' => '111.23',
                    ],
                ],
                'is_multiple_wallet' => true,
            ])
        );
    }

    protected function getTgbotApi_Callback_Request(string $callback): Request
    {
        $data = [
            'update_id' => 80896233,
            'callback_query' => [
                'id' => '123456789',
                'from' => [
                    'id' => 123456789,
                    'is_bot' => '',
                    'first_name' => 'Helo',
                    'last_name' => 'Wu',
                    'username' => $this->username,
                    'language_code' => 'zh-hans',
                ],
                'message' => [
                    'message_id' => 1,
                    'from' => [
                        'id' => 123456789,
                        'is_bot' => '',
                        'first_name' => 'Helo',
                        'last_name' => 'Wu',
                        'username' => $this->username,
                        'language_code' => 'zh-hans',
                    ],
                    'chat' => [
                        'id' => 123456789,
                        'first_name' => 'Helo',
                        'last_name' => 'Wu',
                        'username' => $this->username,
                        'type' => 'private',
                    ],
                    'date' => 1725500589,
                    'text' => 'asd',
                ],
                'chat_instance' => '123456789',
                'data' => $callback,
            ],
        ];

        return Request::create('/', 'POST', $data);
    }

    protected function getTgbotApi_ReplyMessage_Request(bool $contact = false): Request
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
                    'username' => $this->username,
                    'language_code' => 'zh-hans',
                ],
                'chat' => [
                    'id' => 123456789,
                    'first_name' => 'Helo',
                    'last_name' => 'Wu',
                    'username' => $this->username,
                    'type' => 'private',
                ],
                'date' => 1725500589,
                'text' => 'asd',
                'reply_to_message' => [
                    'message_id' => 1,
                    'from' => [
                        'id' => 123456789,
                        'is_bot' => '',
                        'first_name' => 'Helo',
                        'last_name' => 'Wu',
                        'username' => $this->username,
                        'language_code' => 'zh-hans',
                    ],
                    'chat' => [
                        'id' => 123456789,
                        'first_name' => 'Helo',
                        'last_name' => 'Wu',
                        'username' => $this->username,
                        'type' => 'private',
                    ],
                    'date' => 1725500589,
                    'text' => 'asd',
                ],
            ],
        ];

        if ($contact) {
            $data['message']['contact'] = [
                'phone_number' => $this->mobile,
                'first_name' => 'Helo',
                'last_name' => 'Wu',
                'user_id' => 123456789,
            ];
        }

        return Request::create('/', 'POST', $data);
    }
}
