<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\LivechatBot;
use App\Models\Member;
use App\Models\Order;
use App\Models\Prize;
use App\Models\PrizeContent;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Livechat Bot 推薦進度查詢 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/ReferralProgressTest.php
 */
class ReferralProgressTest extends TestCase
{
    private const string ENDPOINT = '/external/api/api-callback/livechat/bot/referral-progress';

    private string $apiKey;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->apiKey = [REDACTED];
        LivechatBot::create([
            'name' => 'test_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $this->agent->id,
            'api_key' => $this->apiKey,
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    public function test_invalid_bearer_returns_401(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/someuser',
            ['Authorization' => 'Bearer wrong-key'],
        );

        $response->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $response = $this->getJson(self::ENDPOINT . '/someuser');

        $response->assertStatus(401);
    }

    public function test_nonexistent_username_returns_404(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/no_such_user_999',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    public function test_existing_member_returns_referral_progress_envelope(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'code',
            'msg',
            'output' => [
                'referral_code',
                'register_referral_apply' => [
                    'current_count',
                    'target_count',
                    'reward_on_completion',
                    'downlines',
                    'message',
                ],
            ],
            'error',
        ]);

        $payload = $response->json();
        $this->assertTrue($payload['status']);
        $this->assertSame(200, $payload['code']);
        $this->assertSame('Success', $payload['msg']);
        $this->assertSame([], $payload['error']);
        $this->assertNotEmpty($payload['output']['referral_code']);
    }

    public function test_v1_active_event_returns_envelope_with_source(): void
    {
        $member = $this->createMember($this->agent);

        // 建立 V1 action-15 邀請活動（Event + active Prize + level-0 tier）。
        $event = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);
        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 0,
            'name' => 'V1 Invite Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ]);
        EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'point' => 999,
            'status' => 1,
            'realname_block' => 0,
        ]);
        PrizeContent::create([
            'prize_id' => $prize->id,
            'name' => 'Tier 3',
            'number' => 1,
            'level' => 0,
            'percentage' => 100,
            'achieve_people' => 3,
            'point' => 100,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('v1', $payload['output']['register_referral_apply']['source']);
        $this->assertSame(3, $payload['output']['register_referral_apply']['target_count']);
        $this->assertSame('100.00', $payload['output']['register_referral_apply']['reward_on_completion']);
    }

    /**
     * V1 level-1 活動 → HTTP 回應含 deposit_referral_apply，JSON 形狀與 deposit_threshold 值正確。
     *
     * 守住 external 回調新表面：Controller/Resource 若漏帶或改動 deposit 維度形狀，此測試轉紅。
     */
    public function test_v1_deposit_dimension_returns_envelope(): void
    {
        $member = $this->createMember($this->agent);

        $event = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);
        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 0,
            'name' => 'V1 Invite Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ]);
        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'point' => 100,
            'status' => 1,
            'realname_block' => 0,
        ]);
        // level-0（大門檻 → register 維度為主）＋ level-1（續儲維度）。
        foreach ([[0, 99, 1000], [1, 2, 300]] as [$level, $target, $reward]) {
            PrizeContent::create([
                'prize_id' => $prize->id,
                'name' => 'Tier ' . $level . '-' . $target,
                'number' => 1,
                'level' => $level,
                'percentage' => 100,
                'achieve_people' => $target,
                'point' => $reward,
                'status' => 1,
                'admin' => 0,
            ]);
        }

        // 一名達標被邀請者：活動窗內 status=3 首單 point=150 >= 100。
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'status' => 1,
            'admin' => 0,
            'username' => 'dep_inv01',
            'nickname' => 'dep_inv01',
        ]);
        EventInviteRecord::create([
            'event_id' => $event->id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        $order = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 150,
            'point' => 150,
        ]);
        $order->created_at = Carbon::parse($detail->start_time)->addHour();
        $order->save();

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'output' => [
                'register_referral_apply',
                'deposit_referral_apply' => [
                    'current_count',
                    'target_count',
                    'reward_on_completion',
                    'downlines',
                    'deposit_threshold',
                    'source',
                    'message',
                ],
            ],
        ]);

        $deposit = $response->json('output.deposit_referral_apply');
        $this->assertSame('v1', $deposit['source']);
        $this->assertSame(1, $deposit['current_count']);
        $this->assertSame(2, $deposit['target_count']);
        $this->assertSame('300.00', $deposit['reward_on_completion']);
        $this->assertSame(['dep_inv01'], $deposit['downlines']);
        $this->assertSame(100, $deposit['deposit_threshold']);
    }
}
