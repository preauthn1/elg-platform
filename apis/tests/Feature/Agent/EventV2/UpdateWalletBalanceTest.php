<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberWallet;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 活動錢包人工調整（後台）：本端點只能碰活動錢包（wallet_type_id=3）。
 *
 * php artisan test tests/Feature/Agent/EventV2/UpdateWalletBalanceTest.php
 */
class UpdateWalletBalanceTest extends TestCase
{
    private string $apiPath = 'agent/api/event-v2/update-wallet-balance';

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'evt_' . uniqid(),
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * 迴歸：傳入主錢包（平台幣）的 wallet_id 不得被加分。
     *
     * 舊寫法 `MemberWallet::where('id', $request->wallet_id)` 未限 wallet_type_id，
     * 而 member_wallets 同表存放主錢包／禮碼錢包，等於可透過活動錢包端點對
     * 可提現的真錢加分。
     */
    public function test_update_wallet_balance_rejects_non_event_wallet(): void
    {
        // cost_type 用 COMPLETE，避開 EventV2UserJoinObserver 的參加成本記帳分支
        // （本測試只驗錢包型別把關，不涉及活動成本統計）
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 1,
            'agent_id' => $this->agent->id,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);

        // 玩家有進行中的活動：舊寫法會一路走到底、真的把錢加到主錢包上
        EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);

        $mainWallet = MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_PC->value,
            'balance' => '100.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath, [
            'wallet_id' => $mainWallet->id,
            'balance' => 999999,
        ]);

        $response->assertStatus(501)
            ->assertJsonPath('code', 501)
            ->assertJsonPath('message', '活動錢包不存在');

        // 主錢包餘額必須完全沒被動過（以數值比對，不綁 decimal 字串格式）
        $this->assertSame(100.0, (float) $mainWallet->refresh()->balance);
    }

    /**
     * 迴歸：不能調整他人代理線的活動錢包。
     *
     * 讀取端 walletManagement() 以活動所屬代理的 hierarchy_path 過濾，
     * 寫入端原本沒擋，任何有效 agent token 都能改任意會員的活動錢包。
     */
    public function test_update_wallet_balance_rejects_event_outside_agent_scope(): void
    {
        // 活動掛在另一條代理線下（本測試的 agent 是 hierarchy_path 為 /{id} 的獨立代理）
        $otherAgent = Agent::factory()->create();
        $otherAgent->update(['hierarchy_path' => "/{$otherAgent->id}"]);

        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 1,
            'agent_id' => $otherAgent->id,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);

        EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);

        $eventWallet = MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
            'balance' => '50.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath, [
            'wallet_id' => $eventWallet->id,
            'balance' => 999999,
        ]);

        $response->assertJsonPath('message', __('error_message.9003'));

        $this->assertSame(50.0, (float) $eventWallet->refresh()->balance);
    }
}
