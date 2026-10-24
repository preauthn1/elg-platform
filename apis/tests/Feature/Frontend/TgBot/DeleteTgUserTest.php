<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\TgBot;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\MemberTelegramRel;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/TgBot/DeleteTgUserTest.php
 */
class DeleteTgUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function test_success()
    {
        $tgUser = $this->createTgUser();
        $this->assertIsInt($tgUser->id);

        // Act
        $delete_user_token = [REDACTED];
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/delete-tg-user',
            data: ['delete_user_token' => $delete_user_token],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );
        $response->assertStatus(200);
        $check = MemberTelegramRel::query()->where('id', $tgUser->id)->first();
        $this->assertNull($check);

        $key = 'tgbot_delete_user_token:' . $delete_user_token;
        $checkRedis = Redis::get($key);
        $this->assertNull($checkRedis);
    }

    protected function createDeleteUserToken()
    {
        $delete_user_token = [REDACTED]';
        $data = [
            'brand_id' => 1,
            'tg_user_id' => 2,
            'member_id' => 3,
            'delete_user_token' => $delete_user_token,
        ];

        $key = 'tgbot_delete_user_token:' . $delete_user_token;
        Redis::set($key, $data, 3600);

        return $delete_user_token;
    }

    protected function createTgUser(): MemberTelegramRel
    {
        $tgUser = new MemberTelegramRel;
        $tgUser->brand_id = 1;
        $tgUser->tg_user_id = 2;
        $tgUser->member_id = 3;
        $tgUser->tg_password = [REDACTED]';
        $tgUser->save();

        return $tgUser;
    }
}
