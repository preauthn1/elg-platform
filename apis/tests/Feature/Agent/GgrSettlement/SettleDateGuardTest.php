<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrSettlement;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\StatusEnum;
use App\Models\AgentGroup;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Models\QueueJobRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ggr:settle 的結算截至日上限（target/tickets#4121）。
 *
 * PeriodResolver 靠「期末須早於錨日」擋下今天所在的期間，但錨＝截至日 +1——
 * --date 指定今天就把錨推到明天，該保護被架空：當日跑到一半的資料被結算落庫，
 * 完成標記與 settled_through 一併推進，當日排程之後就不會再重算該期。
 * 指定未來日期更會一次把多個尚未發生的期間標記完成、游標暴衝。
 */
class SettleDateGuardTest extends TestCase
{
    private GgrPlan $plan;

    /** 今天（GMT+8 業務日）；與命令內的判準同源，不受 BACKEND_TIMEZONE 設定影響 */
    private Carbon $today;

    private Carbon $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = now()->toBackendTime()->startOfDay();
        $this->yesterday = $this->today->copy()->subDay();

        // start_date 設在昨日：正向案例恰好推導出一期，不必 catch-up 整段歷史
        $this->plan = $this->makePlan();
    }

    /** --date=今天：拒絕，且不得留下結算列／完成標記／游標推進 */
    public function test_settle_rejects_today_business_date(): void
    {
        $this->artisan('ggr:settle', [
            '--plan' => (string) $this->plan->id,
            '--date' => $this->today->toDateString(),
            '--direct' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->assertNoSideEffects();
    }

    /** --date=未來日期：同樣拒絕（一次標記多個未發生期間的破壞更大） */
    public function test_settle_rejects_future_date(): void
    {
        $this->artisan('ggr:settle', [
            '--plan' => (string) $this->plan->id,
            '--date' => $this->today->copy()->addDays(7)->toDateString(),
            '--direct' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->assertNoSideEffects();
    }

    /** dry-run 不例外：今天的資料本來就不完整，預覽數字沒有意義 */
    public function test_settle_rejects_today_even_in_dry_run(): void
    {
        $this->artisan('ggr:settle', [
            '--plan' => (string) $this->plan->id,
            '--date' => $this->today->toDateString(),
            '--dry-run' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->assertNoSideEffects();
    }

    /** 昨日是允許的上限值，正常路徑不得被這道驗證誤擋 */
    public function test_settle_accepts_yesterday(): void
    {
        $this->artisan('ggr:settle', [
            '--plan' => (string) $this->plan->id,
            '--date' => $this->yesterday->toDateString(),
            '--direct' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->assertSame(
            $this->yesterday->toDateString(),
            $this->plan->fresh()->settled_through,
            '昨日應照常結算並推進游標',
        );
    }

    /** 不給 --date：預設就是昨日，不會被自己擋下 */
    public function test_settle_without_date_option_runs(): void
    {
        $this->artisan('ggr:settle', ['--plan' => (string) $this->plan->id, '--direct' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($this->yesterday->toDateString(), $this->plan->fresh()->settled_through);
    }

    // ---------------------------------------------------------------- helpers

    /** 被擋下時必須「什麼都沒發生」——半套副作用比直接失敗更難救 */
    private function assertNoSideEffects(): void
    {
        $this->assertSame(
            0,
            GgrSettlement::query()->where('plan_id', $this->plan->id)->count(),
            '不得產生結算列',
        );
        $this->assertSame(
            0,
            QueueJobRun::query()
                ->where('job_key', QueueJobKeyEnum::GGR_SETTLEMENT->value)
                ->where('work_key', (string) $this->plan->id)
                ->count(),
            '不得寫入完成標記',
        );
        $this->assertNull($this->plan->fresh()->settled_through, 'settled_through 不得被推進');
    }

    private function makePlan(): GgrPlan
    {
        $general = $this->createAgentAndDomain();

        $group = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => 1,
            'name' => 'date guard group ' . bin2hex(random_bytes(3)),
            'memo' => '',
            'is_default' => 0,
        ]);

        return GgrPlan::create([
            'general_agent_id' => $general->id,
            'name' => 'date guard plan ' . bin2hex(random_bytes(4)),
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'agent_group_id' => $group->id,
            'start_date' => $this->yesterday->toDateString(),
            'carry_negative' => 0,
            'admin_fee_rate' => '0.00', 'handling_fee' => '0.00', 'ggr_rate' => '100.00', 'bonus_rate' => '0.00',
            'kyc_unique_bank_account' => 0, 'kyc_unique_contact_no' => 0, 'kyc_unique_email' => 0,
            'kyc_unique_ip' => 0, 'kyc_unique_name' => 0, 'kyc_violate_count' => 0,
            'downline_vendor_setting_id' => 0,
            'downline_condition_id' => 0,
            'status' => StatusEnum::ENABLE->value,
            'created_by' => $general->id,
            'admin' => $general->id,
        ]);
    }
}
