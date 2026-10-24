<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Services\ShareHolderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ShareHolderService::getProfit 測試（股東分潤，TP 專屬）
 *
 * php artisan test tests/Unit/Services/ShareHolderProfitTest.php
 */
class ShareHolderProfitTest extends TestCase
{
    private ShareHolderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // 舊版以 UTC+8 計算「昨日」，固定時區避免測試環境差異
        Config::set('custom.frontend.timezone', 'Asia/Taipei');
        $this->service = app(ShareHolderService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 建立某一天（UTC+8）的分潤資料；$payoutStatus = 1 代表已派發
     */
    private function seedProfitDay(Agent $agent, Carbon $frontendDay, string $nettProfit, int $payoutStatus = 1): void
    {
        $groupId = $agent->customized_setting_group_id;
        // summary_time 以 UTC 儲存，取該 UTC+8 日期當日的某個時點
        $summaryTime = $frontendDay->copy()->startOfDay()->addHours(8)->toSystemTime()->toDateTimeString();

        $categoryId = DB::table('shareholder_categories')->insertGetId([
            'agent_group_id' => $groupId,
            'name' => 'cat',
            'code' => 'cat',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        DB::table('summary_shareholder_temps')->insert([
            'summary_time' => $summaryTime,
            'agent_group_id' => $groupId,
            'shareholder_category_id' => $categoryId,
            'share_point' => 10,
            'turnover' => '1000.0000',
            'original_turnover' => '1000.0000',
            'nett_profit' => $nettProfit,
            'original_nett_profit' => $nettProfit,
            'admin' => 0,
        ]);

        DB::table('shareholder_payout_logs')->insert([
            'summary_time' => $summaryTime,
            'agent_group_id' => $groupId,
            'status' => $payoutStatus,
            'admin' => 0,
        ]);
    }

    /**
     * 取一個不與既有資料衝突的代理群組，避免撞到 DB 內既有分潤資料
     */
    private function makeIsolatedAgent(): Agent
    {
        $agent = $this->createAgentAndDomain();
        $freeGroupId = ((int) DB::table('summary_shareholder_temps')->max('agent_group_id')) + 10000 + $agent->id;
        $agent->customized_setting_group_id = $freeGroupId;
        $agent->save();

        return $agent;
    }

    public function test_returns_yesterday_profit_when_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 05:00:00', 'UTC'));

        $agent = $this->makeIsolatedAgent();
        $yesterday = now()->toFrontendTime()->subDay();
        $this->seedProfitDay($agent, $yesterday, '123.4500');

        $result = $this->service->getProfit($agent);

        $this->assertSame($yesterday->format('Y/m/d'), $result['profit_time']);
        $this->assertSame('123.45', $result['total_profit']);
    }

    public function test_walks_back_until_a_paid_out_day_is_found(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 05:00:00', 'UTC'));

        $agent = $this->makeIsolatedAgent();
        // 只有 5 天前有已派發資料，前幾天皆無
        $target = now()->toFrontendTime()->subDays(5);
        $this->seedProfitDay($agent, $target, '999.0000');

        $result = $this->service->getProfit($agent);

        $this->assertSame($target->format('Y/m/d'), $result['profit_time']);
        $this->assertSame('999.00', $result['total_profit']);
    }

    public function test_skips_days_whose_payout_is_not_settled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 05:00:00', 'UTC'));

        $agent = $this->makeIsolatedAgent();
        // 昨天有彙總但派發狀態非 1（未派發）→ 應被跳過
        $this->seedProfitDay($agent, now()->toFrontendTime()->subDay(), '500.0000', payoutStatus: 0);
        $paid = now()->toFrontendTime()->subDays(3);
        $this->seedProfitDay($agent, $paid, '250.0000');

        $result = $this->service->getProfit($agent);

        $this->assertSame($paid->format('Y/m/d'), $result['profit_time']);
        $this->assertSame('250.00', $result['total_profit']);
    }

    /**
     * 舊版此情境為 while(true) 無限迴圈；本端點免登入，故加回溯上限並回 0。
     */
    public function test_returns_zero_instead_of_hanging_when_no_data_within_lookback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 05:00:00', 'UTC'));

        $agent = $this->makeIsolatedAgent();

        $result = $this->service->getProfit($agent);

        $this->assertSame('0', $result['total_profit']);
        // 回溯上限 90 天，profit_time 應停在最後一個檢查日
        $this->assertSame(
            now()->toFrontendTime()->subDays(90)->format('Y/m/d'),
            $result['profit_time']
        );
    }
}
