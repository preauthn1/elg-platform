<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 一次性補丁（target/tickets#4543 第 6 項第一列）：名稱含「總代」的角色改成 admin 持有。
 *
 * 這些角色（VWorldSG 總代、ME77總代…）是 admin 套給總代本人穿的，4543 起「誰持有誰能套」，
 * 持有者若還記著總代，總代就能把自己的角色再套給下級、admin 反而套不到；改成 admin 持有（owner_agent_id NULL）
 * 才符合用途。⚠️ 不是改成 1：1 是 admin預設角色，只能綁 admin 子帳號，會讓它套不給總代（見 AgentPermissionGroup::ADMIN_OWNER_ID）。
 *
 * 一併把 default_setting 清成 0：admin 持有的預設只能有一筆，而且那筆是 admin 建帳號時的兜底，不該由某總代的角色頂替。
 * 原本是某總代預設的（2026-10-19 盤點：VWorld 的 tggame總代 → gffusdt）會因此沒有預設，之後由 admin 到角色管理替該總代
 * 新增「<總代帳號>預設角色」（設為預設、權限不勾，由總代自行設定）——與「建立總代流程」第 3 步相同（Max 2026-10-20：不另寫腳本）。
 *
 * 已綁定的代理不動（agents.agent_permission_group_id 照舊，工單：已綁定的角色不改）。
 * 候選：名稱含「總代」、owner_agent_id 是代理（非 NULL、非 0、非 1）。總代替下線建的「XX總代助理」之類也會符合，
 * 所以**寫入一律要帶 --ids 鎖定盤點過的那幾筆**；不帶 --ids 只能 dry-run 列候選。寫入前把原值記進 log 並印出回滾 SQL。
 *
 * 用法：
 *   php artisan patch:release-top-agent-named-roles --dry-run          # 列全部候選
 *   php artisan patch:release-top-agent-named-roles --ids=2,7 --dry-run
 *   php artisan patch:release-top-agent-named-roles --ids=2,7
 */
class ReleaseTopAgentNamedRolesToAdmin extends Command
{
    protected $signature = 'patch:release-top-agent-named-roles
        {--ids= : 要處理的角色（逗號分隔的 agent_permission_groups.id），仍須名稱含「總代」；寫入時必填}
        {--dry-run : 只預覽，不寫入資料庫}';

    protected $description = '名稱含「總代」的角色改為 admin 持有（owner_agent_id 清空、取消預設），已綁定不動 (target/tickets#4543)';

    private const string NAME_KEYWORD = '總代';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $onlyIds = $this->parseIds();
        if ($onlyIds === null) {
            return self::FAILURE;
        }
        if ($onlyIds === [] && !$isDryRun) {
            $this->error('寫入一律要帶 --ids 鎖定盤點過的角色；要看候選請加 --dry-run');

            return self::FAILURE;
        }

        $groups = $this->candidates()
            ->when($onlyIds !== [], fn ($q) => $q->whereIn('id', $onlyIds))
            ->orderBy('id')
            ->get();

        if ($onlyIds !== [] && $groups->count() !== count($onlyIds)) {
            $missing = array_diff($onlyIds, $groups->pluck('id')->map(fn ($id): int => (int) $id)->all());
            $this->error('--ids 有 ' . count($missing) . ' 個不符合條件（不存在、名稱不含「總代」、或 owner 已是 NULL／0／1）：' . implode(', ', $missing));

            return self::FAILURE;
        }
        if ($groups->isEmpty()) {
            $this->info('沒有符合條件的角色，無事可做');

            return self::SUCCESS;
        }

        $ownerNames = Agent::query()->whereIn('id', $groups->pluck('owner_agent_id'))->pluck('username', 'id');
        $this->table(
            ['id', '角色', '持有者 → NULL', '預設 → 0', '已綁代理數（不動）'],
            $groups->map(fn (AgentPermissionGroup $g): array => [
                $g->id,
                $g->name,
                ($ownerNames[$g->owner_agent_id] ?? '?') . "({$g->owner_agent_id})",
                (int) $g->default_setting === 1 ? '是（原為該總代的預設，之後要補）' : '否',
                $g->agents_count,
            ])->all(),
        );
        $rollback = $groups->map(fn (AgentPermissionGroup $g): string => sprintf(
            'UPDATE agent_permission_groups SET owner_agent_id = %d, default_setting = %d WHERE id = %d;',
            $g->owner_agent_id,
            $g->default_setting,
            $g->id,
        ))->all();
        $this->line('回滾 SQL（寫入前原值）：');
        foreach ($rollback as $sql) {
            $this->line('  ' . $sql);
        }
        $this->line('  ⚠️ 若之後已在角色管理替該總代另建了預設角色，回滾前要先把那筆的「設為預設」關掉，否則同一總代會有兩筆預設');

        if ($isDryRun) {
            $this->info('[dry-run] 未寫入，共 ' . $groups->count() . ' 筆');

            return self::SUCCESS;
        }

        Log::info('patch:release-top-agent-named-roles 寫入前原值（target/tickets#4543）', [
            'rows' => $groups->map(fn (AgentPermissionGroup $g): array => [
                'id' => $g->id, 'name' => $g->name, 'owner_agent_id' => $g->owner_agent_id, 'default_setting' => (int) $g->default_setting,
            ])->all(),
            'rollback_sql' => $rollback,
        ]);

        $ids = $groups->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::transaction(function () use ($ids): void {
            // UPDATE 帶回同一組條件：dry-run 到寫入之間若有人改過這幾筆，筆數對不上就整批回滾
            $updated = $this->candidates()->whereIn('id', $ids)->update(['owner_agent_id' => null, 'default_setting' => 0]);
            if ($updated !== count($ids)) {
                throw new RuntimeException('預期更新 ' . count($ids) . " 筆、實際 {$updated} 筆，資料在預覽後被改過，已整批回滾");
            }
        });

        $lostDefault = $groups->where('default_setting', 1)->pluck('owner_agent_id')->unique()->values();
        $this->info('已更新 ' . count($ids) . ' 筆');
        if ($lostDefault->isNotEmpty()) {
            $this->warn('以下持有者（agents.id）失去預設角色，請 admin 到角色管理替該總代新增「<總代帳號>預設角色」並設為預設：' . $lostDefault->implode(','));
        }

        return self::SUCCESS;
    }

    /** 候選條件集中一處，預覽與寫入用同一組 */
    private function candidates()
    {
        return AgentPermissionGroup::query()
            ->withCount('agents')
            ->where('name', 'like', '%' . self::NAME_KEYWORD . '%')
            ->where('owner_agent_id', '>', AgentPermissionGroup::ADMIN_OWNER_ID);
    }

    /**
     * @return array<int, int>|null 空陣列＝未指定；null＝格式錯誤
     */
    private function parseIds(): ?array
    {
        $raw = trim((string) $this->option('ids'));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!ctype_digit($part)) {
                $this->error("--ids 含非數字的值：{$part}");

                return null;
            }
            $ids[] = (int) $part;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            $this->error('--ids 有給但沒有任何有效的 id');

            return null;
        }

        return $ids;
    }
}
