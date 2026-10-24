<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2Invite;
use App\Models\EventV2UserJoin;
use App\Models\LivechatBot;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\Order;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Services\BloomFilter\MemberFirstNameFilter;
use App\Services\BloomFilter\MemberMobileFilter;
use App\Services\BloomFilter\MemberRegisterIpFilter;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use App\Services\LivechatBot\ReferralProgressService;
use App\Services\MemberCodeService;
use Carbon\Carbon;
use Mockery;
use NXP\MathExecutor;
use Tests\TestCase;

/**
 * Livechat Bot 推薦進度查詢測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/ReferralProgressTest.php
 */
class ReferralProgressTest extends TestCase
{
    private const string INVITE_HANDLER_CLASS = 'InviteFriendService';

    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id)));
        $this->guard = new MemberAgentGuard;
    }

    private function makeBot(int $agentId): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    /**
     * 會員不存在 → throw ApiException(404)
     */
    public function test_throws_404_when_member_not_found(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getProgress('nonexistent_user_999');
    }

    /**
     * 會員無 MemberCode → 自動建立並回傳
     */
    public function test_creates_member_code_when_missing(): void
    {
        $member = $this->createMember($this->agent);

        $this->assertSame(0, MemberCode::where('member_id', $member->id)->count());

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $this->assertNotEmpty($result['referral_code']);
        $this->assertSame(1, MemberCode::where('member_id', $member->id)->count());
    }

    /**
     * 無進行中邀請好友活動 → 預設結構
     */
    public function test_returns_default_when_no_active_invite_event(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $this->assertSame($memberCode->agent_code, $result['referral_code']);
        $this->assertSame(0, $result['register_referral_apply']['current_count']);
        $this->assertSame(0, $result['register_referral_apply']['target_count']);
        $this->assertSame('0.00', $result['register_referral_apply']['reward_on_completion']);
        $this->assertSame([], $result['register_referral_apply']['downlines']);
        // 空結構亦帶 source（null），與有活動結構 schema 一致。
        $this->assertArrayHasKey('source', $result['register_referral_apply']);
        $this->assertNull($result['register_referral_apply']['source']);
        $this->assertContains('Referral Code: ' . $memberCode->agent_code, $result['register_referral_apply']['message']);
        $this->assertContains('No active referral promotion', $result['register_referral_apply']['message']);
    }

    /**
     * 1 個活動進行中 + 0 個下線
     */
    public function test_returns_progress_with_zero_downlines(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $detail = $this->createInviteEventDetail(target: 3, rewardFormula: '100');

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $apply = $result['register_referral_apply'];

        $this->assertSame($memberCode->agent_code, $result['referral_code']);
        $this->assertSame(0, $apply['current_count']);
        $this->assertSame(3, $apply['target_count']);
        $this->assertSame('100.00', $apply['reward_on_completion']);
        $this->assertSame([], $apply['downlines']);
        $this->assertContains('Progress: 0/3', $apply['message']);
        $this->assertContains('Complete 3 more registration referral to get bonus of 100.00!', $apply['message']);
        // detail 用避免 unused 警告
        $this->assertNotNull($detail);
    }

    /**
     * 1 個活動進行中 + 部分下線完成（current < target）
     */
    public function test_returns_progress_when_under_target(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $detail = $this->createInviteEventDetail(target: 3, rewardFormula: '100');

        // 建立 1 名有效下線
        $downline = $this->createDownline(
            agent: $this->agent,
            invitationCode: $memberCode->agent_code,
            ip: '0.0.0.0',
            mobile: '0911000001',
            firstName: 'Alice',
            username: 'alice01',
        );

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $apply = $result['register_referral_apply'];

        $this->assertSame(1, $apply['current_count']);
        $this->assertSame(3, $apply['target_count']);
        $this->assertSame('100.00', $apply['reward_on_completion']);
        $this->assertSame(['alice01'], $apply['downlines']);
        $this->assertContains('Progress: 1/3', $apply['message']);
        $this->assertContains('Registered Downlines: alice01', $apply['message']);
        $this->assertContains('Complete 2 more registration referral to get bonus of 100.00!', $apply['message']);

        // 確認 detail / downline 變數有被引用
        $this->assertNotNull($detail);
        $this->assertNotNull($downline);
    }

    /**
     * 1 個活動進行中 + 達標（current >= target）
     */
    public function test_returns_progress_when_target_reached(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $this->createInviteEventDetail(target: 2, rewardFormula: '100');

        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000001', 'Alice', 'alice01');
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000002', 'Bob', 'bob02');

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $apply = $result['register_referral_apply'];

        $this->assertSame(2, $apply['current_count']);
        $this->assertSame(2, $apply['target_count']);
        $this->assertSame(['alice01', 'bob02'], $apply['downlines']);
        $this->assertContains('Bonus of 100.00 reached!', $apply['message']);
    }

    /**
     * 多個活動進行中 → 取 end_time ASC 第一筆（最緊迫）
     */
    public function test_picks_event_with_earliest_end_time(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        // 較晚結束 (target 5)
        $later = $this->createInviteEventDetail(
            target: 5,
            rewardFormula: '500',
            endTime: now()->addDays(10)->toDateTimeString(),
        );

        // 較早結束 (target 2) — 應被選中
        $earlier = $this->createInviteEventDetail(
            target: 2,
            rewardFormula: '200',
            endTime: now()->addDays(2)->toDateTimeString(),
        );

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $this->assertSame(2, $result['register_referral_apply']['target_count']);
        $this->assertSame('200.00', $result['register_referral_apply']['reward_on_completion']);

        // 抑制未使用警告
        $this->assertNotNull($later);
        $this->assertNotNull($earlier);
        $this->assertNotNull($memberCode);
    }

    /**
     * 下線去重：相同 IP 視為重複，僅保留最早一筆
     */
    public function test_deduplicates_downlines_by_register_ip(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $this->createInviteEventDetail(target: 3, rewardFormula: '100');

        // 兩筆 IP 相同 → 只保留最早 (alice)
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000001', 'Alice', 'alice01');
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000002', 'Bob', 'bob02');
        // 第三筆獨立
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000003', 'Carol', 'carol03');

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $this->assertSame(2, $result['register_referral_apply']['current_count']);
        $this->assertSame(['alice01', 'carol03'], $result['register_referral_apply']['downlines']);
    }

    /**
     * 下線去重：相同手機 / 相同真實姓名同樣會被合併
     */
    public function test_deduplicates_downlines_by_mobile_and_realname(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $this->createInviteEventDetail(target: 5, rewardFormula: '50');

        // mobile 重複
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911999999', 'Alice', 'alice01');
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911999999', 'Bob', 'bob02');

        // realname (first_name) 重複
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000003', 'Same', 'sam03');
        $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0911000004', 'Same', 'sam04');

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        // alice + sam03 共 2 筆有效（bob 因 mobile 重複、sam04 因 first_name 重複被排除）
        $this->assertSame(2, $result['register_referral_apply']['current_count']);
        $downlines = $result['register_referral_apply']['downlines'];
        sort($downlines);
        $this->assertSame(['alice01', 'sam03'], $downlines);
    }

    /**
     * 已被其他活動參加紀錄計算過的下線會被排除
     */
    public function test_excludes_already_invited_downlines(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $this->createInviteEventDetail(target: 3, rewardFormula: '100');

        $alice = $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0921000001', 'Alice', 'alice01');
        $bob = $this->createDownline($this->agent, $memberCode->agent_code, '0.0.0.0', '0921000002', 'Bob', 'bob02');

        // alice 已被別的 user_join 計算過 → 必須被排除
        $userJoinId = $this->insertUserJoin($member->id);
        EventV2Invite::create([
            'event_v2_user_join_id' => $userJoinId,
            'member_id' => $alice->id,
        ]);

        $service = $this->makeService();
        $result = $service->getProgress($member->username);

        $this->assertSame(1, $result['register_referral_apply']['current_count']);
        $this->assertSame(['bob02'], $result['register_referral_apply']['downlines']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        $service = $this->makeService($otherContext);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getProgress($member->username);
    }

    /**
     * 只有 V2 活動 → source = v2（既有行為 + 新增 source 欄位）。
     */
    public function test_only_v2_source_is_v2(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);
        $this->createInviteEventDetail(target: 3, rewardFormula: '100');

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame('v2', $result['register_referral_apply']['source']);
        $this->assertArrayNotHasKey('deposit_referral_apply', $result);
    }

    // ════════════════════════════════════════════════════════════
    //  V1（action 15）register_referral_apply（level-0 純邀請）
    // ════════════════════════════════════════════════════════════

    /**
     * 只有 V1 活動、0 下線 → source=v1、target 取最低未達成級、bonus 取對應 tier point。
     */
    public function test_v1_register_zero_downlines(): void
    {
        $member = $this->createMember($this->agent);
        $memberCode = $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);
        $this->addV1Tier($detail, level: 0, target: 20, reward: 500);

        $result = $this->makeService()->getProgress($member->username);
        $apply = $result['register_referral_apply'];

        $this->assertSame($memberCode->agent_code, $result['referral_code']);
        $this->assertSame('v1', $apply['source']);
        $this->assertSame(0, $apply['current_count']);
        $this->assertSame(3, $apply['target_count']);
        $this->assertSame('100.00', $apply['reward_on_completion']);
        $this->assertSame([], $apply['downlines']);
        $this->assertContains('Progress: 0/3', $apply['message']);
        $this->assertContains('Complete 3 more registration referral to get bonus of 100.00!', $apply['message']);
        $this->assertArrayNotHasKey('deposit_referral_apply', $result);
    }

    /**
     * V1 部分下線（current < target）：current 計有效邀請、downlines 帶 username（created_at 升冪）。
     */
    public function test_v1_register_partial_downlines(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $this->createV1Invitee($detail, $member, 'alice01');
        $this->createV1Invitee($detail, $member, 'bob02');

        $result = $this->makeService()->getProgress($member->username);
        $apply = $result['register_referral_apply'];

        $this->assertSame('v1', $apply['source']);
        $this->assertSame(2, $apply['current_count']);
        $this->assertSame(3, $apply['target_count']);
        $this->assertSame('100.00', $apply['reward_on_completion']);
        $this->assertSame(['alice01', 'bob02'], $apply['downlines']);
        $this->assertContains('Progress: 2/3', $apply['message']);
    }

    /**
     * V1 達標（current >= 最高級）→ required 回最高級、message 顯示 reached。
     */
    public function test_v1_register_target_reached(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 2, reward: 80);

        $this->createV1Invitee($detail, $member, 'alice01');
        $this->createV1Invitee($detail, $member, 'bob02');

        $result = $this->makeService()->getProgress($member->username);
        $apply = $result['register_referral_apply'];

        $this->assertSame(2, $apply['current_count']);
        $this->assertSame(2, $apply['target_count']);
        $this->assertSame('80.00', $apply['reward_on_completion']);
        $this->assertContains('Bonus of 80.00 reached!', $apply['message']);
    }

    /**
     * V1 去重：ip_block=1 時 ip_duplicate=1 的邀請被排除。
     */
    public function test_v1_register_dedups_by_block_flags(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail(detailAttrs: ['ip_block' => 1]);
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $this->createV1Invitee($detail, $member, 'clean01');
        // ip_duplicate=1 → 被排除
        $this->createV1Invitee($detail, $member, 'dup02', inviteAttrs: ['ip_duplicate' => 1]);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame(1, $result['register_referral_apply']['current_count']);
        $this->assertSame(['clean01'], $result['register_referral_apply']['downlines']);
    }

    /**
     * V1 去重：realname_block=1 時，被邀請者 realname_validate=0 不計入。
     */
    public function test_v1_register_requires_realname_when_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail(detailAttrs: ['realname_block' => 1]);
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $this->createV1Invitee($detail, $member, 'verified01', memberAttrs: ['realname_validate' => 1]);
        $this->createV1Invitee($detail, $member, 'unverified02', memberAttrs: ['realname_validate' => 0]);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame(1, $result['register_referral_apply']['current_count']);
        $this->assertSame(['verified01'], $result['register_referral_apply']['downlines']);
    }

    /**
     * 跨級 tier（規則 A）：count 恰達某級門檻 → target 進位到「下一個未達成級」，非停在已達成級。
     *
     * tiers=[3,20]、count=3（恰達 3、未達 20）→ target 應為 20、bonus 取 20 級的 500。
     * 殺 `$tier > $count` → `$tier >= $count` 的 mutation：該 mutation 會讓 3>=3 命中而誤回 3（假達標）。
     */
    public function test_v1_register_target_is_next_unreached_tier(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);
        $this->addV1Tier($detail, level: 0, target: 20, reward: 500);

        // 3 名有效被邀請者 → 恰達第一級 3、未達 20
        foreach (range(1, 3) as $i) {
            $this->createV1Invitee($detail, $member, 'inv0' . $i);
        }

        $result = $this->makeService()->getProgress($member->username);
        $apply = $result['register_referral_apply'];

        $this->assertSame(3, $apply['current_count']);
        $this->assertSame(20, $apply['target_count']);
        $this->assertSame('500.00', $apply['reward_on_completion']);
        $this->assertContains('Progress: 3/20', $apply['message']);
        $this->assertContains('Complete 17 more registration referral to get bonus of 500.00!', $apply['message']);
    }

    /**
     * V1 計數採「列數」不去重，對齊舊系統 getNewInvitePrizeCnt 的 level-0 計數（= V1 派彩基準）。
     *
     * event_invite_records 無 (invite_member_id, member_id) 唯一約束、偶有重複列，仍逐列計入。
     * downlines 另經 whereIn('id') 天然去重，故重複列時 current_count 可能 > downlines 筆數——刻意取捨。
     * 殺「filteredInvitedMemberIds 加 ->unique()」的反向 mutation：去重會使 current_count 變 2（偏少）。
     */
    public function test_v1_register_counts_rows_not_distinct_aligned_with_v1(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 5, reward: 100);

        // alice 有兩筆邀請列（模擬無唯一約束的重複），bob 一筆 → 共 3 列、2 名相異被邀請者
        $alice = $this->createV1Invitee($detail, $member, 'alice01');
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $alice->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        $this->createV1Invitee($detail, $member, 'bob02');

        $result = $this->makeService()->getProgress($member->username);
        $apply = $result['register_referral_apply'];

        // current_count 依列數 = 3（對齊 V1 派彩）；downlines 去重 = 2（alice、bob）。刻意允許 count > downlines。
        $this->assertSame(3, $apply['current_count']);
        $downlines = $apply['downlines'];
        sort($downlines);
        $this->assertSame(['alice01', 'bob02'], $downlines);
        $this->assertContains('Progress: 3/5', $apply['message']);
    }

    // ════════════════════════════════════════════════════════════
    //  V1 deposit_referral_apply（level-1「邀請並充值」，V1-only）
    // ════════════════════════════════════════════════════════════

    /**
     * 有 level-1 tier → 輸出 deposit_referral_apply：達標被邀請者（首單 status=3、point>=門檻）。
     */
    public function test_v1_deposit_dimension_emitted_and_counts_qualified(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail(detailAttrs: ['point' => 100]);
        // 純邀請大門檻 → register 維度不達標而為主；續儲維度照常輸出
        $this->addV1Tier($detail, level: 0, target: 99, reward: 1000);
        $this->addV1Tier($detail, level: 1, target: 2, reward: 300);

        // A：首單 point=150 >= 100 → 計入
        $this->createV1DepositInvitee($detail, $member, 'aaa01', point: 150);
        // B：首單 point=50 < 100 → 不計入
        $this->createV1DepositInvitee($detail, $member, 'bbb02', point: 50);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertArrayHasKey('deposit_referral_apply', $result);
        $deposit = $result['deposit_referral_apply'];
        $this->assertSame('v1', $deposit['source']);
        $this->assertSame(1, $deposit['current_count']);
        $this->assertSame(2, $deposit['target_count']);
        $this->assertSame('300.00', $deposit['reward_on_completion']);
        $this->assertSame(['aaa01'], $deposit['downlines']);
        $this->assertSame(100, $deposit['deposit_threshold']);
        $this->assertContains('Deposit Referral Bonus: 300.00', $deposit['message']);
        $this->assertContains('Minimum Deposit: 100 points', $deposit['message']);
    }

    /**
     * 無 level-1 tier → 不輸出 deposit_referral_apply（向後相容）。
     */
    public function test_v1_deposit_dimension_absent_without_level_one_tier(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail();
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertArrayHasKey('register_referral_apply', $result);
        $this->assertArrayNotHasKey('deposit_referral_apply', $result);
    }

    /**
     * 達標比 orders.point 而非 orders.amount：兩者不同時，point 決定計入與否。
     *
     * A：amount=999、point=150（>=100）→ 計入；B：amount=999、point=50（<100）→ 不計入。
     * 若比較誤用 amount，兩者 amount 皆 999>=100 → current_count 變 2、downlines 含 bbb02。
     */
    public function test_v1_deposit_dimension_compares_point_not_amount(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail(detailAttrs: ['point' => 100]);
        $this->addV1Tier($detail, level: 0, target: 99, reward: 1000);
        $this->addV1Tier($detail, level: 1, target: 2, reward: 300);

        // A：point=150 >= 100（amount 遠高於門檻但無關）→ 計入
        $this->createV1DepositInvitee($detail, $member, 'aaa01', point: 150, amount: 999);
        // B：point=50 < 100（amount=999 >= 100，若誤比 amount 會誤計）→ 不計入
        $this->createV1DepositInvitee($detail, $member, 'bbb02', point: 50, amount: 999);

        $result = $this->makeService()->getProgress($member->username);

        $deposit = $result['deposit_referral_apply'];
        $this->assertSame(1, $deposit['current_count']);
        $this->assertSame(['aaa01'], $deposit['downlines']);
    }

    /**
     * 首單 MIN(id) 語意：僅看被邀請者活動窗內首筆訂單是否達標，非任一單達標即計。
     *
     * 被邀請者首單 point=50（<100）、第二單 point=200（>=100）→ 因首單未達標而不計入。
     * 殺 MIN(id)→MAX(id)、「任一單達標即計」、去 groupBy 去重三種 mutation。
     */
    public function test_v1_deposit_dimension_only_counts_first_order(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $detail = $this->createV1InviteDetail(detailAttrs: ['point' => 100]);
        $this->addV1Tier($detail, level: 0, target: 99, reward: 1000);
        $this->addV1Tier($detail, level: 1, target: 2, reward: 300);

        // 對照組：首單即達標的 A，確保維度本身有輸出且只計 A
        $this->createV1DepositInvitee($detail, $member, 'aaa01', point: 150);

        // 焦點：同一被邀請者兩筆訂單，首單（先建立=較小 id、較早 created_at）未達標
        $laggard = $this->createV1Invitee($detail, $member, 'lag02');
        $this->addV1DepositOrder($detail, $laggard, point: 50, hourOffset: 1);
        $this->addV1DepositOrder($detail, $laggard, point: 200, hourOffset: 2);

        $result = $this->makeService()->getProgress($member->username);

        $deposit = $result['deposit_referral_apply'];
        $this->assertSame(1, $deposit['current_count']);
        $this->assertSame(['aaa01'], $deposit['downlines']);
        $this->assertNotContains('lag02', $deposit['downlines']);
    }

    // ════════════════════════════════════════════════════════════
    //  仲裁（V1 vs V2）
    // ════════════════════════════════════════════════════════════

    /**
     * V1 + V2 同時進行、V1 end_time 較早 → 採 V1（source=v1）。
     */
    public function test_arbitration_prefers_earlier_end_time_v1(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        // V2 較晚結束
        $this->createInviteEventDetail(target: 5, rewardFormula: '500', endTime: now()->addDays(10)->toDateTimeString());
        // V1 較早結束 → 應勝出
        $detail = $this->createV1InviteDetail(detailAttrs: ['end_time' => now()->addDays(2)]);
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame('v1', $result['register_referral_apply']['source']);
        $this->assertSame(3, $result['register_referral_apply']['target_count']);
    }

    /**
     * V1 + V2 同時進行、V2 end_time 較早 → 採 V2（source=v2）。
     */
    public function test_arbitration_prefers_earlier_end_time_v2(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        // V2 較早結束 → 應勝出
        $this->createInviteEventDetail(target: 7, rewardFormula: '700', endTime: now()->addDays(2)->toDateTimeString());
        // V1 較晚結束
        $detail = $this->createV1InviteDetail(detailAttrs: ['end_time' => now()->addDays(10)]);
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame('v2', $result['register_referral_apply']['source']);
        $this->assertSame(7, $result['register_referral_apply']['target_count']);
    }

    /**
     * V1 + V2 同 end_time → V2 優先。
     */
    public function test_arbitration_tie_prefers_v2(): void
    {
        $member = $this->createMember($this->agent);
        $this->createMemberCode($member);

        $sharedEnd = now()->addDays(3)->toDateTimeString();
        $this->createInviteEventDetail(target: 4, rewardFormula: '400', endTime: $sharedEnd);
        $detail = $this->createV1InviteDetail(detailAttrs: ['end_time' => $sharedEnd]);
        $this->addV1Tier($detail, level: 0, target: 3, reward: 100);

        $result = $this->makeService()->getProgress($member->username);

        $this->assertSame('v2', $result['register_referral_apply']['source']);
        $this->assertSame(4, $result['register_referral_apply']['target_count']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * 建立一個「當期可參加」的 V1 action-15 邀請活動（Event + active Prize + EventDetail）。
     *
     * @param  array<string, mixed>  $detailAttrs
     */
    private function createV1InviteDetail(array $detailAttrs = []): EventDetail
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
        ]);

        Prize::create([
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

        return EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'point' => 999,
            'status' => 1,
            'realname_block' => 0,
        ], $detailAttrs));
    }

    /**
     * 在 detail 對應 prize 下新增一筆分級 prize_content（achieve_people 門檻 + point 獎勵）。
     */
    private function addV1Tier(EventDetail $detail, int $level, int $target, int $reward, int $status = 1): void
    {
        $prize = Prize::query()->where('event_id', $detail->event_id)->firstOrFail();

        PrizeContent::create([
            'prize_id' => $prize->id,
            'name' => 'Tier ' . $level . '-' . $target,
            'number' => 1,
            'level' => $level,
            'percentage' => 100,
            'achieve_people' => $target,
            'point' => $reward,
            'status' => $status,
            'admin' => 0,
        ]);
    }

    /**
     * 建立一名有效被邀請者（Member + EventInviteRecord，四個 *_duplicate 預設 0）。
     *
     * @param  array<string, mixed>  $inviteAttrs
     * @param  array<string, mixed>  $memberAttrs
     */
    private function createV1Invitee(
        EventDetail $detail,
        Member $member,
        string $username,
        array $inviteAttrs = [],
        array $memberAttrs = [],
    ): Member {
        $invitee = Member::factory()->create(array_merge([
            'parent' => $this->agent->id,
            'status' => 1,
            'admin' => 0,
            'username' => $username,
            'nickname' => $username,
        ], $memberAttrs));

        EventInviteRecord::create(array_merge([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ], $inviteAttrs));

        return $invitee;
    }

    /**
     * 建立一名被邀請者，並附帶活動窗內 status=3 首單（point 指定），供 level-1 續儲維度測試。
     *
     * $amount 預設等於 $point；欲驗證「達標比 point 不比 amount」時可指定與 point 不同的面額。
     */
    private function createV1DepositInvitee(
        EventDetail $detail,
        Member $member,
        string $username,
        int $point,
        ?int $amount = null,
    ): Member {
        $invitee = $this->createV1Invitee($detail, $member, $username);

        $order = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => $amount ?? $point,
            'point' => $point,
        ]);
        $order->created_at = Carbon::parse($detail->start_time)->addHour();
        $order->save();

        return $invitee;
    }

    /**
     * 在指定被邀請者名下新增一筆活動窗內 status=3 訂單（point 指定、created_at 依 $hourOffset 排序）。
     *
     * 供「首單 MIN(id) 語意」測試建立同一被邀請者的多筆訂單；先建立者 id 較小、created_at 較早。
     */
    private function addV1DepositOrder(
        EventDetail $detail,
        Member $invitee,
        int $point,
        int $hourOffset,
    ): Order {
        $order = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => $point,
            'point' => $point,
        ]);
        $order->created_at = Carbon::parse($detail->start_time)->addHours($hourOffset);
        $order->save();

        return $order;
    }

    private function makeService(?LivechatBotContext $context = null): ReferralProgressService
    {
        // BloomFilter 依賴 Redis，測試中以 Mock 模擬「無重複」行為
        $ipFilter = Mockery::mock(MemberRegisterIpFilter::class);
        $ipFilter->shouldReceive('getDuplicateData')->andReturn([]);

        $mobileFilter = Mockery::mock(MemberMobileFilter::class);
        $mobileFilter->shouldReceive('getDuplicateData')->andReturn([]);

        $firstNameFilter = Mockery::mock(MemberFirstNameFilter::class);
        $firstNameFilter->shouldReceive('getDuplicateData')->andReturn([]);

        return new ReferralProgressService(
            memberCodeService: app(MemberCodeService::class),
            memberRegisterIpFilter: $ipFilter,
            memberMobileFilter: $mobileFilter,
            memberFirstNameFilter: $firstNameFilter,
            executor: new MathExecutor,
            context: $context ?? $this->context,
            guard: $this->guard,
        );
    }

    private function createInviteEventDetail(
        int $target,
        string $rewardFormula,
        ?string $endTime = null,
    ): EventV2Detail {
        $eventV2 = EventV2::create([
            'name' => 'Invite Friends',
            'handler_class' => self::INVITE_HANDLER_CLASS,
        ]);

        return EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
            'condition_config' => ['invite' => $target],
            'reward_formula' => $rewardFormula,
            'start_time' => now()->subDay(),
            'end_time' => $endTime ?? now()->addDays(7)->toDateTimeString(),
            'status' => 1,
        ]);
    }

    private function createDownline(
        Agent $agent,
        string $invitationCode,
        string $ip,
        string $mobile,
        string $firstName,
        string $username,
    ): Member {
        return Member::factory()->create([
            'parent' => $agent->id,
            'status' => 1,
            'admin' => 0,
            'username' => $username,
            'invitation_code' => $invitationCode,
            'register_ip' => ip2long($ip),
            'mobile' => $mobile,
            'first_name' => $firstName,
        ]);
    }

    private function insertUserJoin(int $memberId): int
    {
        $eventV2 = EventV2::create([
            'name' => 'Old Invite Friends',
            'handler_class' => self::INVITE_HANDLER_CLASS,
        ]);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $memberId,
            'order_id' => 0,
            'reward' => 0,
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) EventV2UserJoin::query()
            ->where('member_id', $memberId)
            ->where('event_v2_detail_id', $detail->id)
            ->value('id');
    }
}
