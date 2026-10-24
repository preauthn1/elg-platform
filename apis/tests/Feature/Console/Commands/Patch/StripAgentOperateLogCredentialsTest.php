<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Patch;

use App\Models\Agent;
use App\Models\AgentOperateGeneralLog;
use App\Models\AgentOperateLog;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * fix:strip-agent-operate-log-credentials 的行為測試。
 *
 * 一律以 --from-id／--to-id 限定在本測試建立的列，不碰測試庫既有資料；DatabaseTransactions 回滾。
 *
 * php artisan test tests/Feature/Console/Commands/Patch/StripAgentOperateLogCredentialsTest.php
 */
class StripAgentOperateLogCredentialsTest extends TestCase
{
    private const string COMMAND = 'fix:strip-agent-operate-log-credentials';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();
    }

    public function test_strips_credentials_from_operate_log_and_keeps_other_columns(): void
    {
        $dirty = $this->operateLog(json_encode([
            'originalDataJson' => ['status' => 1, 'password' => 'hash', 'payment_password' => 'hash', 'CS_API_KEY' => 'k'],
            'request_info' => ['id' => 9, 'status' => 0, 'TOKEN' => 'live-token', 'USERNAME' => 'op', 'new_payment_password' => '654321'],
        ]));
        $plain = $this->operateLog('status=1');
        $clean = $this->operateLog('{"old":{"status":21},"attributes":{"status":29}}');

        $this->runCommand('agent_operate_logs', $dirty->id, $clean->id)->assertSuccessful();

        $after = AgentOperateLog::findOrFail($dirty->id);
        $this->assertSame(
            json_encode(['originalDataJson' => ['status' => 1], 'request_info' => ['id' => 9, 'status' => 0]]),
            $after->debug_info,
        );
        $this->assertEquals($dirty->updated_at, $after->updated_at);
        $this->assertSame('status=1', AgentOperateLog::findOrFail($plain->id)->debug_info);
        $this->assertSame($clean->debug_info, AgentOperateLog::findOrFail($clean->id)->debug_info);
    }

    public function test_strips_credentials_from_general_log(): void
    {
        $log = AgentOperateGeneralLog::query()->create([
            'operator_id' => $this->agent->id,
            'page_path' => 'api/agent/edit',
            'request_method' => 'POST',
            'ip' => '0.0.0.0',
            'content' => ['TOKEN' => 'live-token', 'secret' => '123456', 'agent_id' => 5],
        ]);

        $this->runCommand('agent_operate_general_logs', $log->id, $log->id)->assertSuccessful();

        $this->assertSame(['agent_id' => 5], AgentOperateGeneralLog::findOrFail($log->id)->content);
    }

    public function test_dry_run_does_not_write(): void
    {
        $original = json_encode(['request_info' => ['TOKEN' => 'live-token']]);
        $log = $this->operateLog($original);

        $this->runCommand('agent_operate_logs', $log->id, $log->id, dryRun: true)
            ->expectsOutputToContain('預計清理 1 筆')
            ->assertSuccessful();

        $this->assertSame($original, AgentOperateLog::findOrFail($log->id)->debug_info);
    }

    public function test_rejects_unknown_table(): void
    {
        $this->artisan(self::COMMAND, ['--table' => 'agents'])->assertExitCode(SymfonyCommand::FAILURE);
    }

    private function operateLog(string $debugInfo): AgentOperateLog
    {
        $log = AgentOperateLog::query()->create([
            'agent_id' => $this->agent->id,
            'type' => 'update_agent',
            'reference_id' => (string) $this->agent->id,
            'reference_type' => 'agent',
            'debug_info' => $debugInfo,
            'ip' => '0.0.0.0',
        ]);
        $log->forceFill(['updated_at' => now()->subDay()])->save();

        return $log->fresh();
    }

    private function runCommand(string $table, int $fromId, int $toId, bool $dryRun = false): PendingCommand
    {
        return $this->artisan(self::COMMAND, [
            '--table' => $table,
            '--from-id' => $fromId,
            '--to-id' => $toId,
            '--dry-run' => $dryRun,
        ]);
    }
}
