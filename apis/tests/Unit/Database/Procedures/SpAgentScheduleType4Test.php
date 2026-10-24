<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * {@see database/procedures/SP_agent_schedule.sql} — schedule_type_id = 4（佔成錢包封頂額度）。
 *
 * {@see TestCase::ensureStoredProcedureExists()} 斷言 `SP_agent_schedule` 已存在且 `procedure_hashes` 與 repo SQL 檔 md5 一致（不在測試內執行 DDL）。
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/SpAgentScheduleType4Test.php
 * ```
 */
class SpAgentScheduleType4Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureStoredProcedureExists('SP_agent_schedule');
    }

    #[Test]
    public function schedule_type_4_inserts_cap_setting_when_absent(): void
    {
        $agent = $this->createAgentAndDomain();

        $this->insertAgentScheduleLog(agentId: $agent->id, dataValue: '8888');

        DB::statement('CALL SP_agent_schedule(?)', [4]);

        $this->assertDatabaseHas('agent_personal_settings', [
            'agent_id' => $agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT,
            'sub_category' => '',
            'value' => '8888',
        ]);

        $log = DB::table('agent_schedule_logs')
            ->where('agent_id', $agent->id)
            ->where('schedule_type_id', 4)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(1, (int) $log->status);
        $this->assertStringContainsString('PROFIT_SHARE_WALLET_CAP_AMOUNT(95110):', (string) $log->memo);
        $this->assertStringContainsString(' > 8888', (string) $log->memo);
    }

    #[Test]
    public function schedule_type_4_updates_cap_setting_and_memo_shows_before_after(): void
    {
        $agent = $this->createAgentAndDomain();

        $this->createAgentPersonalSetting($agent, AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT, '-1');

        $this->insertAgentScheduleLog(agentId: $agent->id, dataValue: '10000');

        DB::statement('CALL SP_agent_schedule(?)', [4]);

        $this->assertDatabaseHas('agent_personal_settings', [
            'agent_id' => $agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT,
            'value' => '10000',
        ]);

        $log = DB::table('agent_schedule_logs')
            ->where('agent_id', $agent->id)
            ->where('schedule_type_id', 4)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(1, (int) $log->status);
        $this->assertStringContainsString('-1 > 10000', (string) $log->memo);
    }

    /**
     * 排程值與現值相同時不得中斷整批排程（op_ticket/2860）。
     */
    #[Test]
    public function schedule_type_4_keeps_processing_when_cap_value_unchanged(): void
    {
        $agent = $this->createAgentAndDomain();

        // 先由排程寫入一次，讓設定列與排程寫入的內容（含 admin = 51）完全一致
        $this->insertAgentScheduleLog(agentId: $agent->id, dataValue: '6');
        DB::statement('CALL SP_agent_schedule(?)', [4]);

        $this->insertAgentScheduleLog(agentId: $agent->id, dataValue: '6');    // 與現值相同
        $this->insertAgentScheduleLog(agentId: $agent->id, dataValue: '3888'); // 後續排程

        DB::statement('CALL SP_agent_schedule(?)', [4]);

        $statuses = DB::table('agent_schedule_logs')
            ->where('agent_id', $agent->id)
            ->where('schedule_type_id', 4)
            ->orderBy('id')
            ->pluck('status')
            ->map(intval(...))
            ->all();

        $this->assertSame([1, 1, 1], $statuses);

        $this->assertSame(1, DB::table('agent_personal_settings')
            ->where('agent_id', $agent->id)
            ->where('setting_id', AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT)
            ->count());

        $this->assertDatabaseHas('agent_personal_settings', [
            'agent_id' => $agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT,
            'sub_category' => '',
            'value' => '3888',
        ]);
    }

    /**
     * @param  non-empty-string  $dataValue
     */
    private function insertAgentScheduleLog(int $agentId, string $dataValue): void
    {
        $past = now()->subDays(7);
        DB::table('agent_schedule_logs')->insert([
            'agent_id' => $agentId,
            'data_value' => $dataValue,
            'schedule_type_id' => 4,
            'status' => 0,
            'created_at' => $past,
            'updated_at' => $past,
        ]);
    }
}
