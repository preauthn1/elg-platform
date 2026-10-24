<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\FlowLimitStatusEnum;
use App\Enums\FlowLimitTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentSettingDefine;
use App\Models\CouponBatch;
use App\Models\Event;
use App\Models\EventName;
use App\Models\FlowLimit;
use App\Models\LoginEvent;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員稽核流水列表與取消（target/tickets#4438，由 API_Agent member/flowLimit、member/cancel_flow_limit 移植）。
 *
 * 必須成立：
 * 1. 列表新→舊、排除優惠錢包（104）；來源名稱依類型取活動／登入活動／優惠碼批次號；獎勵取自交易紀錄。
 * 2. status_zero_exists 反映是否還有未通過（同樣排除 104）。
 * 3. 取消全部：只改未通過且非 104 的流水，會員 event_mode 歸 0；沒有可取消的回錯。
 * 4. 取消單筆：只能取消未通過；鎖分模式（105）那筆會一併解除 event_mode。
 * 5. 範圍外會員的列表與取消都擋下。
 *
 * php artisan test tests/Feature/Agent/Member/MemberFlowLimitTest.php
 */
class MemberFlowLimitTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        // 列表依會員上級的多錢包開關決定獎勵來源，測試庫沒有這筆設定定義
        AgentSettingDefine::query()->firstOrCreate(
            ['setting_id' => AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH],
            ['parent' => 6000, 'type' => 3, 'memo' => '多錢包開關', 'language_text_code' => 'multiple_wallet_switch', 'default_value' => '0', 'data_type' => 'int', 'created_by' => 0],
        );
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'fl' . bin2hex(random_bytes(5)), 'event_mode' => 1]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $this->agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_list_resolves_titles_and_bonus_and_excludes_promotion_wallet(): void
    {
        $event = Event::factory()->create(['title' => 'default title']);
        EventName::query()->create(['event_id' => $event->id, 'language_id' => LanguageEnum::EN->value, 'title' => 'event en title', 'content' => '']);
        $loginEvent = LoginEvent::query()->create([
            'title' => 'login event title', 'content' => '', 'agent_id' => $this->agent->id, 'type' => 1, 'how_days' => 0, 'point' => 0, 'times' => 1,
            'number_of_people' => 0, 'number_of_participants' => 0, 'start_time' => '2026-01-01 00:00:00', 'end_time' => '2099-01-01 00:00:00',
            'repeat_hour' => 0, 'status' => 1, 'admin' => 1,
        ]);
        $coupon = CouponBatch::query()->create([
            'batch_code' => 'CB' . bin2hex(random_bytes(4)), 'agent_id' => $this->agent->id, 'type' => 1, 'quantity' => 1,
            'start_time' => '2026-01-01 00:00:00', 'end_time' => '2099-01-01 00:00:00', 'admin' => 1,
        ]);
        $log = TransactionLog::query()->create([
            'transaction_no' => 'T' . bin2hex(random_bytes(6)), 'member_id' => $this->member->id, 'type' => TransactionEnum::ACTIVITY_AWARD->value,
            'point' => '12.5', 'vendor_point' => 0, 'rate' => 1, 'operator' => 'test', 'admin' => 1,
        ]);

        $eventRow = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['event_id' => $event->id, 'reference_id' => $log->id, 'flow_limit' => '100']);
        $loginRow = $this->flowLimit(TransactionEnum::LOGIN_EVENT_REWARDS->value, ['event_id' => $loginEvent->id, 'status' => FlowLimitStatusEnum::PASSED->value]);
        $couponRow = $this->flowLimit(TransactionEnum::COUPON_CODE->value, ['event_id' => -$coupon->id]);
        $promotion = $this->flowLimit(TransactionEnum::PROMOTION_WALLET_REWARD->value);

        $data = $this->list()->assertOk()->assertJsonPath('code', 200)->json('data');

        $ids = array_column($data['items'], 'id');
        $this->assertSame([$couponRow->id, $loginRow->id, $eventRow->id], $ids, '新→舊，不含優惠錢包');
        $this->assertNotContains($promotion->id, $ids);

        $byId = array_column($data['items'], null, 'id');
        $this->assertSame('event en title', $byId[$eventRow->id]['title'], '活動名稱取該語系');
        $this->assertSame('12.5000', $byId[$eventRow->id]['bonus']);
        $this->assertSame('100.0000', $byId[$eventRow->id]['flow_limit']);
        $this->assertSame('login event title', $byId[$loginRow->id]['title']);
        $this->assertSame($coupon->batch_code, $byId[$couponRow->id]['title']);
        $this->assertSame('0.0000', $byId[$couponRow->id]['bonus'], '找不到交易紀錄時獎勵為 0');
        $this->assertSame(__('param.flow_limit_status.1'), $byId[$loginRow->id]['status_text']);
        $this->assertSame(3, $data['pagination']['total']);
        $this->assertTrue($data['status_zero_exists']);
    }

    public function test_multi_wallet_bonus_falls_back_to_wallet_transaction_log(): void
    {
        $walletLog = WalletTransactionLog::query()->create([
            'transaction_no' => 'W' . bin2hex(random_bytes(6)), 'member_id' => $this->member->id, 'point' => '8',
            'remark' => '', 'checksum' => '', 'result' => '', 'operator' => 'test',
        ]);
        $row = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['wallet_transaction_log_id' => $walletLog->id]);

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 0);
        $this->assertSame('0.0000', $this->list()->json('data.items.0.bonus'), '單錢包站不看錢包交易紀錄（同舊接口）');

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        $this->assertSame($row->id, $this->list()->json('data.items.0.id'));
        $this->assertSame('8.0000', $this->list()->json('data.items.0.bonus'), '多錢包站主錢包找不到時取錢包交易紀錄');
    }

    public function test_status_zero_exists_ignores_promotion_wallet(): void
    {
        $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['status' => FlowLimitStatusEnum::PASSED->value]);
        $this->flowLimit(TransactionEnum::PROMOTION_WALLET_REWARD->value);

        $this->assertFalse($this->list()->assertOk()->json('data.status_zero_exists'));
    }

    public function test_cancel_all_cancels_pending_only_and_unlocks_event_mode(): void
    {
        $pending = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value);
        $passed = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['status' => FlowLimitStatusEnum::PASSED->value]);
        $promotion = $this->flowLimit(TransactionEnum::PROMOTION_WALLET_REWARD->value);

        $this->cancel(['member_id' => $this->member->id, 'remark' => 'clear all'])->assertOk()->assertJsonPath('code', 200);

        $pending->refresh();
        $this->assertSame(FlowLimitStatusEnum::CANCELLED->value, (int) $pending->status);
        $this->assertSame('clear all', $pending->remark);
        $this->assertSame($this->agent->id, (int) $pending->admin);
        $this->assertSame(FlowLimitStatusEnum::PASSED->value, (int) $passed->refresh()->status, '已通過不動');
        $this->assertSame(FlowLimitStatusEnum::PENDING->value, (int) $promotion->refresh()->status, '優惠錢包不動');
        $this->assertSame(0, (int) $this->member->refresh()->event_mode);
    }

    public function test_cancel_all_without_pending_is_rejected_and_keeps_event_mode(): void
    {
        $this->flowLimit(TransactionEnum::PROMOTION_WALLET_REWARD->value);

        $this->assertNotSame(200, $this->cancel(['member_id' => $this->member->id])->json('code'));
        $this->assertSame(1, (int) $this->member->refresh()->event_mode);
    }

    public function test_cancel_one_lock_mode_row_unlocks_event_mode(): void
    {
        $lockMode = $this->flowLimit(FlowLimitTypeEnum::EVENT_LOCK_MODE->value);

        $this->cancel(['id' => $lockMode->id, 'remark' => 'one'])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(FlowLimitStatusEnum::CANCELLED->value, (int) $lockMode->refresh()->status);
        $this->assertSame(0, (int) $this->member->refresh()->event_mode);
    }

    public function test_cancel_one_other_type_keeps_event_mode(): void
    {
        $row = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value);

        $this->cancel(['id' => $row->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(FlowLimitStatusEnum::CANCELLED->value, (int) $row->refresh()->status);
        $this->assertSame(1, (int) $this->member->refresh()->event_mode);
    }

    public function test_cancel_one_rejects_non_pending_and_promotion_wallet(): void
    {
        $passed = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['status' => FlowLimitStatusEnum::PASSED->value]);
        $promotion = $this->flowLimit(TransactionEnum::PROMOTION_WALLET_REWARD->value);

        $this->assertNotSame(200, $this->cancel(['id' => $passed->id])->json('code'));
        $this->assertNotSame(200, $this->cancel(['id' => $promotion->id])->json('code'));
        $this->assertSame(FlowLimitStatusEnum::PASSED->value, (int) $passed->refresh()->status);
        $this->assertSame(FlowLimitStatusEnum::PENDING->value, (int) $promotion->refresh()->status);
    }

    public function test_member_outside_agent_tree_is_rejected(): void
    {
        // createAgentAndDomain 共用 $this->domain，第二棵樹需換新域名避免撞唯一鍵
        $this->domain = 'fl' . bin2hex(random_bytes(5)) . '.test.local';
        $outsider = $this->createMember($this->createAgentAndDomain(), ['username' => 'flo' . bin2hex(random_bytes(5))]);
        $row = $this->flowLimit(TransactionEnum::ACTIVITY_AWARD->value, ['member_id' => $outsider->id]);

        $this->assertNotSame(200, $this->list($outsider->id)->json('code'));
        $this->assertNotSame(200, $this->cancel(['member_id' => $outsider->id])->json('code'));
        $this->assertNotSame(200, $this->cancel(['id' => $row->id])->json('code'));
        $this->assertSame(FlowLimitStatusEnum::PENDING->value, (int) $row->refresh()->status);
    }

    private function flowLimit(int $type, array $attributes = []): FlowLimit
    {
        return FlowLimit::query()->create(array_merge([
            'member_id' => $this->member->id,
            'type' => $type,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => '50',
            'reach' => '0',
            'status' => FlowLimitStatusEnum::PENDING->value,
            'admin' => 0,
        ], $attributes));
    }

    private function list(?int $memberId = null): TestResponse
    {
        return $this->postJson('agent/api/member/flow-limits', ['member_id' => $memberId ?? $this->member->id], $this->headers);
    }

    private function cancel(array $payload): TestResponse
    {
        return $this->postJson('agent/api/member/flow-limits/cancel', $payload, $this->headers);
    }
}
