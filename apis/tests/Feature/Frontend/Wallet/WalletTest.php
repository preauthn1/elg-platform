<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Wallet;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Wallet/WalletTest.php
 */
class WalletTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
    }

    #[Group('rules/currency-and-wallet-currency#R-12')]
    public function test_transfer_to_game_success()
    {
        // 給點錢；type=2 已廢除改為只查不建（決議⑬），存量錢包由測試預建——
        // 守的是「存量錢包還在的過渡期，互轉功能照舊」
        $this->member->update([
            'balance' => 1000,
        ]);
        MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 0,
        ]);

        // 轉帳 100 到遊戲錢包
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/transfer',
            data: [
                'type' => 1,
                'amount' => 100,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert 轉帳成功
        $response->assertStatus(200);

        $this->member->refresh();

        // Assert 主錢包餘額減少 100
        $this->assertEquals(900, $this->member->balance);

        // 遊戲錢包
        $gameWallet = MemberWallet::lockForUpdate()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->first();

        // Assert 遊戲錢包餘額增加 100
        $this->assertEquals(100, $gameWallet->balance);

        // 查詢主錢包紀錄
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/main-logs',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('1000.0000', $data['items'][0]['before_balance']);
        $this->assertEquals(-100, $data['items'][0]['point']);
        $this->assertEquals('900.0000', $data['items'][0]['balance']);

        // 查詢遊戲錢包紀錄
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/game-logs',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('0.0000', $data['items'][0]['before_balance']);
        $this->assertEquals(100, $data['items'][0]['point']);
        $this->assertEquals('100.0000', $data['items'][0]['balance']);
    }

    #[Group('rules/currency-and-wallet-currency#R-11')]
    #[Group('rules/currency-and-wallet-currency#R-12')]
    public function test_transfer_to_game_rejected_when_no_game_wallet()
    {
        // Arrange：會員沒有 type=2 存量錢包——已廢除類型只查不建（決議⑬），
        // 轉入被業務錯誤拒絕、不得自動建立
        $this->member->update([
            'balance' => 1000,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/transfer',
            data: [
                'type' => 1,
                'amount' => 100,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert：被拒、主錢包分文未動、沒有錢包被建出來
        $response->assertStatus(400);
        $this->assertEquals(1000, $this->member->refresh()->balance);
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);
    }

    public function test_transfer_to_main_success()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_FLOW_LIMIT_SETTING, 1);

        // 先建立遊戲錢包
        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 1000,
            'first_deposit' => null,
            'status' => 1,
            'admin' => 0,
        ]);

        // 轉帳 100 到遊戲錢包
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/transfer',
            data: [
                'type' => 2,
                'amount' => 100,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);

        // 查詢主錢包紀錄
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/main-logs',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('0.0000', $data['items'][0]['before_balance']);
        $this->assertEquals(100, $data['items'][0]['point']);
        $this->assertEquals('100.0000', $data['items'][0]['balance']);

        // 查詢遊戲錢包紀錄
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/wallet/game-logs',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('1000.0000', $data['items'][0]['before_balance']);
        $this->assertEquals(-100, $data['items'][0]['point']);
        $this->assertEquals('900.0000', $data['items'][0]['balance']);
    }
}
