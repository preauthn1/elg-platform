<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StatusEnum;
use App\Enums\Vendor\GameMaintainTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Game;
use App\Models\GameMaintenance;
use App\Models\GameMaintenanceGame;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 遊戲維護（粒度到單一遊戲） 工單4120
 *
 * 與遊戲商維護（vendor_maintenances）平行的另一套，互不影響：
 * 一張單可跨遊戲商勾任意一批遊戲，實際清單存在 game_maintenance_games。
 *
 * 生效判定不靠排程：前台每次請求即時比對時間區間
 * （見 VendorService::getMaintenanceGameIds），時間到自動生效／恢復。
 */
class GameMaintenanceService
{
    /**
     * 維護單列表
     *
     * 平臺（遊戲商）篩選採「涵蓋即列出」：一張單可跨商，只要其中有任一款
     * 屬於該遊戲商的遊戲就列出——營運查某商是為了看「有哪些維護影響到它」。
     *
     * @param  array{status?: int|string|null, vendor_id?: int|string|null, per_page?: int}  $filters
     */
    public function list(Agent $operator, array $filters): LengthAwarePaginator
    {
        $vendorId = $filters['vendor_id'] ?? null;

        return GameMaintenance::query()
            ->with(['games.game:id,vendor_id,type_id,name', 'games.game.vendor:id,name'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn (Builder $q) => $q->where('status', (int) $filters['status'])
            )
            ->when($vendorId, fn (Builder $q) => $q->whereHas(
                'games.game',
                fn (Builder $sub) => $sub->where('vendor_id', (int) $vendorId)
            ))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** 單筆詳情（含涵蓋的遊戲清單） */
    public function view(Agent $operator, int $id): ?GameMaintenance
    {
        return GameMaintenance::query()
            ->with(['games.game:id,vendor_id,type_id,name', 'games.game.vendor:id,name'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->find($id);
    }

    /**
     * 新增／編輯維護單
     *
     * 遊戲清單採整批覆寫：送來的即為最終清單，未列出的既有關聯刪除。
     * 主表與子表包在同一交易，避免出現「單子改了時間但遊戲沒換」的中間態。
     *
     * @param  array<int>  $gameIds
     * @return int 維護單 id
     */
    public function save(Agent $operator, ?int $id, array $payload, array $gameIds): int
    {
        $this->assertAgentsInScope($operator, $payload);

        return DB::transaction(function () use ($operator, $id, $payload, $gameIds): int {
            $attributes = [
                'type' => GameMaintainTypeEnum::GAME_CLOSED->value,
                // 前端送的是前台時區（台北）的字面時間，DB 一律存 UTC——
                // 與遊戲商維護一致（該處由舊 API_Agent 的 convertToUTC 做同樣的事）
                'start_time' => Carbon::parseFrontend($payload['start_time'])->toSystemTime()->toDateTimeString(),
                'end_time' => Carbon::parseFrontend($payload['end_time'])->toSystemTime()->toDateTimeString(),
                'agent_id' => (int) $payload['agent_id'],
                'exclude_agent_id' => (int) ($payload['exclude_agent_id'] ?? 0),
                'status' => (int) ($payload['status'] ?? StatusEnum::ENABLE->value),
                'unlock_type' => (int) ($payload['unlock_type'] ?? 1),
                'memo' => $payload['memo'] ?? null,
                'admin' => (int) $operator->id,
            ];

            if ($id !== null) {
                $maintenance = GameMaintenance::query()
                    ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
                    ->lockForUpdate()
                    ->findOrFail($id);
                $maintenance->update($attributes);
            } else {
                $maintenance = GameMaintenance::query()->create($attributes);
            }

            $this->syncGames($maintenance, $gameIds);

            return (int) $maintenance->id;
        });
    }

    /**
     * 維護代理與排除代理都必須是操作者自己或下線（4495）。
     *
     * 維護會沿 hierarchy 對該代理的下線一併生效，若可任選代理，低權限帳號即可對別線（甚至總代）
     * 的玩家關閉遊戲。0 的 exclude_agent_id 表示不排除，不需檢查。
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ApiException
     */
    private function assertAgentsInScope(Agent $operator, array $payload): void
    {
        $agentIds = [(int) $payload['agent_id']];
        $excludeAgentId = (int) ($payload['exclude_agent_id'] ?? 0);

        if ($excludeAgentId !== 0) {
            $agentIds[] = $excludeAgentId;
        }

        foreach ($agentIds as $agentId) {
            if (!$operator->canSeeAgent($agentId)) {
                throw new ApiException(__('error_message.9003'));
            }
        }
    }

    /**
     * 差集覆寫涵蓋的遊戲：只增刪異動的部分，不整批砍掉重建
     * （整批刪除會讓 id 一直遞增，且併發下有短暫「無任何遊戲」的空窗）。
     *
     * @param  array<int>  $gameIds
     */
    private function syncGames(GameMaintenance $maintenance, array $gameIds): void
    {
        $gameIds = array_values(array_unique(array_map('intval', $gameIds)));

        $existing = GameMaintenanceGame::query()
            ->where('game_maintenance_id', $maintenance->id)
            ->pluck('game_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toAdd = array_diff($gameIds, $existing);
        $toRemove = array_diff($existing, $gameIds);

        if (!empty($toRemove)) {
            GameMaintenanceGame::query()
                ->where('game_maintenance_id', $maintenance->id)
                ->whereIn('game_id', $toRemove)
                ->delete();
        }

        if (!empty($toAdd)) {
            GameMaintenanceGame::query()->insert(
                array_map(fn (int $gameId) => [
                    'game_maintenance_id' => $maintenance->id,
                    'game_id' => $gameId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], array_values($toAdd))
            );
        }
    }

    /**
     * 表單用：依遊戲商與類型篩出可勾選的遊戲
     *
     * 遊戲商／類型只是縮小清單的過濾器，不會落地——真正存起來的只有勾選的 game_id。
     *
     * @param  array<int>  $vendorIds
     * @param  array<int>  $typeIds
     * @return \Illuminate\Support\Collection<int, Game>
     */
    public function selectableGames(array $vendorIds, array $typeIds = [], ?string $keyword = null)
    {
        return Game::query()
            ->where('status', StatusEnum::ENABLE->value)
            ->whereIn('vendor_id', $vendorIds)
            ->when(!empty($typeIds), fn (Builder $q) => $q->whereIn('type_id', $typeIds))
            ->when($keyword, fn (Builder $q) => $q->where('name', 'like', '%' . $keyword . '%'))
            ->with('vendor:id,name')
            ->orderBy('vendor_id')
            ->orderBy('type_id')
            ->orderBy('id')
            ->get(['id', 'vendor_id', 'type_id', 'name']);
    }

    /**
     * 可見範圍：自己建立的 + 下線建立的維護單（沿用 MemberGroupService 的既有模式）
     *
     * ⚠️ 不可用 $agent->hierarchy_array——那是「上級」鏈（由近到遠的祖先），
     * 前台判定「有沒有上層對我設維護」才用它；後台要看的是下線。
     *
     * fail-closed：hierarchy_path 取不到時退化成只看自己，不放寬成全站。
     */
    private function applyVisibilityScope(Builder $query, Agent $operator): void
    {
        if ($this->isAdmin($operator)) {
            return;
        }

        $ownerId = $this->ownerId($operator);
        $path = $this->effectiveHierarchyPath($operator);

        if ($path === null || $path === '') {
            $query->where('agent_id', $ownerId);

            return;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('agent_id', $ownerId)
                ->orWhereIn('agent_id', function ($sub) use ($path) {
                    // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                    $sub->select('id')->from('agents')->where('hierarchy_path', 'like', $path . '/%');
                });
        });
    }

    /** 子帳號（host_id != 0）的資料歸屬於其主帳號 */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        if ($agent->host_id != 0) {
            return Agent::find($agent->host_id)?->hierarchy_path;
        }

        return $agent->hierarchy_path;
    }

    private function ownerId(Agent $agent): int
    {
        return $agent->host_id != 0 ? (int) $agent->host_id : (int) $agent->id;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }
}
