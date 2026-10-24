<?php

declare(strict_types=1);

namespace Tests\Unit\Services\EventV2;

use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\Member;
use App\Services\EventV2\DailyFreeService;
use App\Services\EventV2\DownlineCommissionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 活動 V2 的 reward_config／condition_config 是自由 JSON，日後會多帶 key（例如積分有效期 expire）。
 * 讀取端只能對「真的有的 key」動作，不能因為整包 config 非 null 就直接讀特定 key。
 *
 * 兩處守衛（target/tickets#4231）：
 * - AbstractEventV2::convertRewardFormula()：reward_config 沒有 limit 就不封頂（原本 Undefined array key → TypeError）。
 * - DownlineCommissionService::getTimeRange()：condition_config 非空但沒有 dayOfWeek，與整包為空同義，抓上一週。
 *
 * 另以正式站現有的配置形狀（{"limit":N}、{"dayOfWeek":7}、null）當回歸錨點，鎖住既有活動零行為差。
 *
 * php artisan test tests/Unit/Services/EventV2/ConfigKeyGuardTest.php
 */
class ConfigKeyGuardTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    /**
     * 測試庫的 event_v2 類型 id 與 dev／正式不同，一律以 handler_class 反查，沒有就補一列。
     */
    private function eventV2Id(string $handlerClass, string $name): int
    {
        return EventV2::query()->firstOrCreate(
            ['handler_class' => $handlerClass],
            ['name' => $name, 'schedule' => null],
        )->id;
    }

    /**
     * 每日登入（DailyFreeService）：公式型，getReward() 走 convertRewardFormula()。
     *
     * @param  array<string, mixed>|null  $rewardConfig
     */
    private function createDailyFreeDetail(?array $rewardConfig): EventV2Detail
    {
        return EventV2Detail::factory()->create([
            'event_v2_id' => $this->eventV2Id('DailyFreeService', '每日登入'),
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '100',
            'reward_config' => $rewardConfig,
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ]);
    }

    /**
     * 下線傭金（DownlineCommissionService）：getTimeRange() 讀 condition_config['dayOfWeek']。
     *
     * @param  array<string, mixed>|null  $conditionConfig
     */
    private function createDownlineDetail(?array $conditionConfig): EventV2Detail
    {
        return EventV2Detail::factory()->create([
            'event_v2_id' => $this->eventV2Id('DownlineCommissionService', '下線傭金'),
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => $conditionConfig,
            'reward_formula' => 'win_lose * 0.1',
            'reward_config' => null,
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function callGetTimeRange(EventV2Detail $detail): array
    {
        $method = new ReflectionMethod(DownlineCommissionService::class, 'getTimeRange');

        return $method->invoke(app(DownlineCommissionService::class), $detail, $this->member);
    }

    // -------------------------------------------------------
    // convertRewardFormula：reward_config 的 limit 守衛
    // -------------------------------------------------------

    public function test_reward_config_without_limit_keeps_formula_result(): void
    {
        $detail = $this->createDailyFreeDetail(['expire' => 720]);

        $reward = app(DailyFreeService::class)->getReward($detail, $this->member);

        $this->assertSame(100.0, $reward, 'config 只有 expire 沒有 limit：不封頂、不炸');
    }

    public function test_reward_config_limit_still_caps_when_other_keys_present(): void
    {
        $detail = $this->createDailyFreeDetail(['limit' => 30, 'expire' => 720]);

        $reward = app(DailyFreeService::class)->getReward($detail, $this->member);

        $this->assertSame(30.0, $reward, 'limit 與其他 key 並存時封頂仍生效');
    }

    public function test_reward_config_null_keeps_formula_result_as_before(): void
    {
        $detail = $this->createDailyFreeDetail(null);

        $reward = app(DailyFreeService::class)->getReward($detail, $this->member);

        $this->assertSame(100.0, $reward, '正式站多數活動 reward_config 為 null：行為不變');
    }

    public function test_reward_config_limit_only_caps_as_before(): void
    {
        $detail = $this->createDailyFreeDetail(['limit' => 60]);

        $reward = app(DailyFreeService::class)->getReward($detail, $this->member);

        $this->assertSame(60.0, $reward, '正式站 {"limit":N} 形狀：行為不變');
    }

    // -------------------------------------------------------
    // DownlineCommissionService::getTimeRange：condition_config 的 dayOfWeek 守衛
    // -------------------------------------------------------

    public function test_downline_time_range_without_day_of_week_matches_empty_config(): void
    {
        $baseline = $this->callGetTimeRange($this->createDownlineDetail(null));
        $withOtherKey = $this->callGetTimeRange($this->createDownlineDetail(['score' => 200]));

        $this->assertSame($baseline, $withOtherKey, 'config 非空但沒 dayOfWeek：與整包為空同義（抓上一週），不炸');
    }

    public function test_downline_time_range_with_day_of_week_unchanged(): void
    {
        $baseline = $this->callGetTimeRange($this->createDownlineDetail(null));
        $prodShape = $this->callGetTimeRange($this->createDownlineDetail(['dayOfWeek' => 7]));

        $this->assertSame($baseline, $prodShape, '正式站 {"dayOfWeek":7} 形狀：dayOfWeek > 0 抓上一週，行為不變');
    }
}
