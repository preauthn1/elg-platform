<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Event;
use App\Models\EventName;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\PrizeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PrizeService::getClaimedList 測試（舊版已領取獎項紀錄 api/prizes）
 *
 * php artisan test tests/Unit/Services/PrizeServiceTest.php
 */
class PrizeServiceTest extends TestCase
{
    private PrizeService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(PrizeService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, prize: Prize, content: PrizeContent}
     */
    private function makePrize(string $title, array $contentOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'title' => $title,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => '1000.0000',
            'expire_date' => now()->addYear(),
            'status' => 1,
        ]);

        $content = PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content-' . Str::random(4),
            'number' => 1,
            'level' => 0,
            'point' => 100,
            'percentage' => 0,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'status' => 1,
            'admin' => 0,
        ], $contentOverrides));

        return compact('event', 'prize', 'content');
    }

    private function claim(array $ctx, int $status = 1, array $overrides = []): PrizeMember
    {
        return PrizeMember::create(array_merge([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => $ctx['content']->id,
            'status' => $status,
            'admin' => 0,
        ], $overrides));
    }

    public function test_returns_only_claimed_records_of_the_member(): void
    {
        $claimed = $this->makePrize('claimed');
        $this->claim($claimed, 1);

        // 未領取（status 0）不列入
        $pending = $this->makePrize('pending');
        $this->claim($pending, 0);

        // 他人的紀錄不列入
        $others = $this->makePrize('others');
        $otherMember = $this->createMember($this->agent, ['username' => 'other_' . Str::random(10)]);
        PrizeMember::create([
            'member_id' => $otherMember->id,
            'prize_id' => $others['prize']->id,
            'content_id' => $others['content']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->service->getClaimedList($this->member, 1, 1, 30);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('claimed', $result['data'][0]->event_name);
    }

    public function test_event_name_falls_back_to_default_title_without_language_row(): void
    {
        $ctx = $this->makePrize('default title');
        EventName::create([
            'event_id' => $ctx['event']->id,
            'language_id' => 2,
            'title' => 'localized title',
            'content' => '',
        ]);
        $this->claim($ctx);

        $withoutLang = $this->service->getClaimedList($this->member, 1, 1, 30);
        $this->assertSame('default title', $withoutLang['data'][0]->event_name);

        $withLang = $this->service->getClaimedList($this->member, 2, 1, 30);
        $this->assertSame('localized title', $withLang['data'][0]->event_name);
    }

    public function test_percentage_content_uses_lottery_point(): void
    {
        // type = 1 時取 prize_members.lottery_point（截斷至小數 2 位）
        $ctx = $this->makePrize('percentage', ['type' => 1, 'point' => 100]);
        $this->claim($ctx, 1, ['lottery_point' => '33.3333']);

        $result = $this->service->getClaimedList($this->member, 1, 1, 30);

        $this->assertSame(33.33, (float) $result['data'][0]->point);
    }

    public function test_fixed_content_uses_content_point(): void
    {
        $ctx = $this->makePrize('fixed', ['type' => 0, 'point' => 88]);
        $this->claim($ctx, 1, ['lottery_point' => '11.1111']);

        $result = $this->service->getClaimedList($this->member, 1, 1, 30);

        $this->assertSame(88.0, (float) $result['data'][0]->point);
    }

    /**
     * 舊版對 groupBy 後的 query 直接 count()，total 恆為 1；此處改用 getCountForPagination
     */
    public function test_total_counts_every_record_and_pages_correctly(): void
    {
        $ctx = $this->makePrize('paged');
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->claim($ctx)->id;
        }

        $first = $this->service->getClaimedList($this->member, 1, 1, 2);
        $second = $this->service->getClaimedList($this->member, 1, 2, 2);
        $third = $this->service->getClaimedList($this->member, 1, 3, 2);

        $this->assertSame(5, $first['total']);
        $this->assertCount(2, $first['data']);
        $this->assertCount(2, $second['data']);
        $this->assertCount(1, $third['data']);

        // 排序：updated_at desc, id desc
        $returned = array_merge(
            $first['data']->pluck('id')->all(),
            $second['data']->pluck('id')->all(),
            $third['data']->pluck('id')->all(),
        );
        rsort($ids);
        $this->assertSame($ids, $returned);
    }

    public function test_returns_empty_when_member_has_no_claimed_prize(): void
    {
        $result = $this->service->getClaimedList($this->member, 1, 1, 30);

        $this->assertSame(0, $result['total']);
        $this->assertCount(0, $result['data']);
    }
}
