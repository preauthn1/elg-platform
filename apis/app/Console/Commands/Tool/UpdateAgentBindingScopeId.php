<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan agent:update-binding-scope-id
 * php artisan agent:update-binding-scope-id 12345 5
 * php artisan agent:update-binding-scope-id vbet 8 --dry-run
 */
final class UpdateAgentBindingScopeId extends Command
{
    protected $signature = 'agent:update-binding-scope-id
                            {agent_id? : 代理 ID 或 username，省略則批次處理預設清單}
                            {binding_scope_id? : 小組織 ID}
                            {--dry-run : 只顯示將更新的代理，不寫入}';

    protected $description = '更新代理商及其下線的小組織 ID (binding_scope_id)';

    /** @var array<string, int> */
    protected array $agents = [
        '77win' => 1,
        'togo' => 2,
        'WINT' => 3,
        'vmax' => 4,
        'hmlogo' => 5,
        'the1' => 6,
        'vwmmc' => 7,
        'vbet' => 8,
        'vclasscrd' => 9,
        'v8cd' => 10,
        'deluxecd' => 11,
        'Vclass' => 12,
        'vstar' => 13,
        'redbull88' => 14,
        'ewlt' => 15,
        'seasia' => 16,
        'v7logo' => 17,
        'aceworld' => 18,
        'v6logo' => 19,
        'v8logo' => 20,
        'spkl' => 21,
        'elgadmin' => 22,
    ];

    public function handle(): int
    {
        $targets = $this->resolveTargets();

        if ($targets === null) {
            return Command::FAILURE;
        }

        if ($targets === []) {
            $this->warn('沒有需要處理的代理');

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('※ Dry-run 模式：只顯示統計，不寫入資料');
            $this->newLine();
        } elseif (!$this->confirm('確定要更新 binding_scope_id？', false)) {
            $this->info('指令已取消');

            return Command::SUCCESS;
        }

        $totalUpdated = 0;

        foreach ($targets as $target) {
            $updated = $this->updateAgentAndDownlines(
                lookup: $target['lookup'],
                bindingScopeId: $target['binding_scope_id'],
                byId: $target['by_id'],
                isDryRun: $isDryRun,
            );

            if ($updated === null) {
                continue;
            }

            $totalUpdated += $updated;
        }

        $this->newLine();
        $this->info("完成，共更新 {$totalUpdated} 筆代理");

        return Command::SUCCESS;
    }

    /**
     * @return list<array{lookup: string|int, binding_scope_id: int, by_id: bool}>|null
     */
    private function resolveTargets(): ?array
    {
        $agentIdArg = $this->argument('agent_id');
        $bindingScopeIdArg = $this->argument('binding_scope_id');

        if ($agentIdArg === null && $bindingScopeIdArg === null) {
            return array_map(
                fn (int $bindingScopeId, string $username): array => [
                    'lookup' => $username,
                    'binding_scope_id' => $bindingScopeId,
                    'by_id' => false,
                ],
                $this->agents,
                array_keys($this->agents),
            );
        }

        if ($agentIdArg === null || $bindingScopeIdArg === null) {
            $this->error('請同時提供 agent_id 與 binding_scope_id，或兩者皆省略以批次處理預設清單');

            return null;
        }

        if (!is_numeric((string) $bindingScopeIdArg)) {
            $this->error('binding_scope_id 必須為整數');

            return null;
        }

        $bindingScopeId = (int) $bindingScopeIdArg;

        if (is_numeric((string) $agentIdArg)) {
            return [[
                'lookup' => (int) $agentIdArg,
                'binding_scope_id' => $bindingScopeId,
                'by_id' => true,
            ]];
        }

        return [[
            'lookup' => (string) $agentIdArg,
            'binding_scope_id' => $bindingScopeId,
            'by_id' => false,
        ]];
    }

    private function updateAgentAndDownlines(
        string|int $lookup,
        int $bindingScopeId,
        bool $byId,
        bool $isDryRun,
    ): ?int {
        $agent = $byId
            ? Agent::query()->where('id', $lookup)->where('host_id', 0)->first()
            : Agent::query()->where('username', $lookup)->where('host_id', 0)->first();

        if ($agent === null) {
            $this->error("找不到代理：{$lookup}");

            return null;
        }

        $targetAgentIds = Agent::query()
            ->where(function ($query) use ($agent): void {
                $query->where('id', $agent->id)
                    ->orWhere('hierarchy_path', 'like', $agent->hierarchy_path . '/%');
            })
            ->pluck('id')
            ->all();

        $agentsToUpdate = Agent::query()
            ->whereIn('id', $targetAgentIds)
            ->where('binding_scope_id', '!=', $bindingScopeId)
            ->get(['id', 'username', 'binding_scope_id']);

        $this->info("代理 {$agent->username} (id={$agent->id}) → binding_scope_id={$bindingScopeId}");
        $this->line('  範圍共 ' . count($targetAgentIds) . ' 筆，待更新 ' . $agentsToUpdate->count() . ' 筆');

        if ($agentsToUpdate->isEmpty()) {
            return 0;
        }

        if ($isDryRun) {
            $this->table(
                ['id', 'username', '目前 binding_scope_id'],
                $agentsToUpdate->map(fn (Agent $row): array => [
                    $row->id,
                    $row->username,
                    $row->binding_scope_id,
                ])->toArray(),
            );

            return $agentsToUpdate->count();
        }

        try {
            DB::transaction(function () use ($agentsToUpdate, $bindingScopeId): void {
                Agent::query()
                    ->whereIn('id', $agentsToUpdate->pluck('id'))
                    ->update(['binding_scope_id' => $bindingScopeId]);
            });
        } catch (Throwable $e) {
            $this->error("代理 {$agent->username} 更新失敗：{$e->getMessage()}");

            return null;
        }

        $this->line("  已更新 {$agentsToUpdate->count()} 筆");

        return $agentsToUpdate->count();
    }
}
