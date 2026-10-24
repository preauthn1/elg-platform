<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\NewbieChallenge;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\NewbieChallengeConfig;
use App\Models\NewbieChallengeProgress;
use App\Models\TransactionLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * target/tickets#4352：新人專享挑戰彩金核銷（confirm-deposit）比照活動 V2，代理組別設置
 * AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT 開啟時，後台開分
 * （transaction_logs type=DEPOSIT，無對應 orders 列）也視同一筆合格儲值。
 *
 * php artisan test tests/Feature/Frontend/NewbieChallenge/BackendDepositConfirmTest.php
 */
class BackendDepositConfirmTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private NewbieChallengeConfig $config;

    private string $confirmDepositPath = 'frontend/api/newbie-challenge/confirm-deposit';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent, ['balance' => 1000]);

        $this->config = NewbieChallengeConfig::create([
            'agent_id' => null,
            'activity_amount' => 88,
            'challenge_flow_multiple' => 5,
            'bonus_flow_multiple' => 12,
            'min_unlock_amount' => 30,
            'max_unlock_amount' => 1888,
            'bonus_window_hours' => 48,
            'allowed_vendor_ids' => [],
            'bonus_flow_vendor_ids' => null,
            'restrict_new_member' => 0,
            'status' => 1,
        ]);

        $this->withoutMiddleware(DecryptRequest::class);
    }

    private function createPendingProgress(): NewbieChallengeProgress
    {
        return NewbieChallengeProgress::create([
            'config_id' => $this->config->id,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::BONUS_PENDING->value,
            'activity_reward' => 88,
            'unlocked_bonus_amount' => 50,
            'bonus_window_expire_at' => now()->addHours(48),
            'principal_total' => 0,
            'challenge_completed_at' => now()->subHour(),
        ]);
    }

    /** @return TransactionLog 模擬 CS 後台 newTransaction() type=DEPOSIT 建立的紀錄：沒有 orders 列 */
    private function createBackendDeposit(float $point = 50): TransactionLog
    {
        return TransactionLog::create([
            'transaction_no' => 'ut_' . uniqid(),
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => $point,
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => 1000,
            'balance' => 1000 + $point,
            'vendor_balance' => 0,
            'status' => 1000,
            'remark' => '',
            'result' => '',
            'operator' => 'cs_operator',
            'admin' => 0,
        ]);
    }

    public function test_confirm_deposit_fails_when_backend_deposit_disabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT, 0);
        $progress = $this->createPendingProgress();
        $this->createBackendDeposit();

        $response = $this->requestApi($this->confirmDepositPath, []);

        // 開關關閉時，後台開分不算儲值，沒有其他真實儲值訂單 → 查無符合資格的儲值紀錄
        $response->assertStatus(400);
        $this->assertDatabaseHas('newbie_challenge_progress', [
            'id' => $progress->id,
            'stage' => NewbieChallengeStageEnum::BONUS_PENDING->value,
        ]);
    }

    public function test_confirm_deposit_succeeds_with_backend_deposit_when_enabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT, 1);
        $progress = $this->createPendingProgress();
        $transactionLog = $this->createBackendDeposit();

        $response = $this->requestApi($this->confirmDepositPath, []);

        $response->assertStatus(200);

        $this->assertDatabaseHas('newbie_challenge_progress', [
            'id' => $progress->id,
            'stage' => NewbieChallengeStageEnum::BONUS_WAGERING->value,
            'deposit_transaction_log_id' => $transactionLog->id,
            'deposit_order_id' => null,
            'deposit_amount' => '50.0000',
        ]);
    }
}
