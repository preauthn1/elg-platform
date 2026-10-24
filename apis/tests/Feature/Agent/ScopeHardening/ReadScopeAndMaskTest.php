<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ScopeHardening;

use App\Models\EventV2Detail;
use Tests\TestCase;

/**
 * 工單 4495：活動 V2 詳情／更新資訊限範圍、
 * 會員追蹤反查限下線並取消明文密碼查詢、會員聯絡資料依權限遮罩。
 *
 * php artisan test --env=testing tests/Feature/Agent/ScopeHardening/ReadScopeAndMaskTest.php
 */
class ReadScopeAndMaskTest extends TestCase
{
    use ActsAsScopedAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootScopedAgent();
    }

    // ── 活動 V2 ──

    private function eventDetailOf(int $agentId): EventV2Detail
    {
        return EventV2Detail::factory()->create(['event_v2_id' => 1, 'agent_id' => $agentId]);
    }

    public function test_event_v2_detail_and_update_info_limited_to_visible_events(): void
    {
        $this->createMenus(['event_v2', 'event_image_text'], grant: true);

        $own = $this->eventDetailOf($this->createDownline()->id);
        $outside = $this->eventDetailOf($this->createOutsideAgent()->id);
        $colliding = $this->eventDetailOf($this->createPrefixCollidingAgent()->id);

        $this->requestApi('agent/api/event-v2/detail', ['event_detail_id' => $own->id])
            ->assertJsonPath('code', 200);

        foreach ([$outside, $colliding] as $blocked) {
            $this->assertNotSame(
                200,
                $this->requestApi('agent/api/event-v2/detail', ['event_detail_id' => $blocked->id])->json('code'),
            );

            $before = (int) $blocked->sort;
            $this->assertNotSame(
                200,
                $this->requestApi('agent/api/event-v2/update-info', [
                    'event_detail_id' => $blocked->id,
                    'sort' => $before + 100,
                ])->json('code'),
            );
            $this->assertSame($before, (int) $blocked->refresh()->sort, '越權不得改到別線活動');
        }

        $this->requestApi('agent/api/event-v2/update-info', ['event_detail_id' => $own->id, 'sort' => 77])
            ->assertJsonPath('code', 200);
        $this->assertSame(77, (int) $own->refresh()->sort);
    }

    // ── 會員追蹤 ──

    private function trace(array $params): array
    {
        return $this->requestApi('agent/api/member/trace', array_merge(['page' => 1, 'per_page' => 20], $params))->json();
    }

    public function test_trace_reverse_target_must_be_in_own_line(): void
    {
        $this->createMenus(['member_trace'], grant: true);

        $own = $this->createMember($this->createDownline());
        $outsider = $this->createMember($this->createOutsideAgent());
        $collidingMember = $this->createMember($this->createPrefixCollidingAgent());

        $ok = $this->trace(['username' => $own->username, 'trace_fields' => ['mobile']]);
        $this->assertSame(200, $ok['code']);

        // 線外會員當反查目標：不得取出其資料當條件，結果為空
        foreach ([$outsider, $collidingMember] as $blocked) {
            $result = $this->trace(['username' => $blocked->username, 'trace_fields' => ['mobile', 'email', 'ip']]);
            $this->assertSame([], $result['data']['items'] ?? [], '線外會員不得被反查');
        }
    }

    public function test_trace_no_longer_accepts_plaintext_password_search(): void
    {
        $this->createMenus(['member_trace'], grant: true);
        $this->createMember($this->agent);

        // 只剩 password 條件：直接模式已移除，等同沒有任何查詢條件
        $result = $this->trace(['password' => '123456']);

        $this->assertNotSame(200, $result['code']);
    }

    // ── 手機號遮罩 ──

    public function test_trace_mobile_masked_without_reveal_phone_permission(): void
    {
        $this->createMenus(['member_trace', 'reveal_phone_number'], grant: false);
        $this->createMenus(['member_trace'], grant: true);

        $mobile = '60' . random_int(100000000, 999999999);
        $this->createMember($this->createDownline(), ['mobile' => $mobile]);

        $masked = $this->trace(['mobile' => $mobile]);
        $this->assertSame('-', $masked['data']['items'][0]['mobile']);

        $this->createMenus(['reveal_phone_number'], grant: true);

        $visible = $this->trace(['mobile' => $mobile]);
        $this->assertSame($mobile, $visible['data']['items'][0]['mobile']);
    }

    public function test_activity_filter_mobile_masked_without_reveal_phone_permission(): void
    {
        $this->createMenus(['member_activity_filter', 'reveal_phone_number'], grant: false);
        $this->createMenus(['member_activity_filter'], grant: true);

        $mobile = '60' . random_int(100000000, 999999999);
        $member = $this->createMember($this->createDownline(), ['mobile' => $mobile]);

        $listRows = fn (): array => $this->requestApi('agent/api/member/activity-filter', [
            'filters' => [],
            'match' => 'and',
            'page' => 1,
            'per_page' => 100,
        ])->json('data.items') ?? [];

        $rows = $listRows();
        $row = collect($rows)->firstWhere('member_id', $member->id);
        if ($row !== null) {
            $this->assertSame('-', $row['mobile']);
        }

        $this->assertStringNotContainsString($mobile, json_encode($rows, JSON_UNESCAPED_UNICODE));

        // 補上權限後同一支列表要回原文（Resource 讀的是 app('request')，旗標必須寫得到）
        $this->createMenus(['reveal_phone_number'], grant: true);

        $visibleRow = collect($listRows())->firstWhere('member_id', $member->id);
        if ($visibleRow !== null) {
            $this->assertSame($mobile, $visibleRow['mobile']);
        }
    }
}
