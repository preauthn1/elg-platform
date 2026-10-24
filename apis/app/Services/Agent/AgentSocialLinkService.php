<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentSocialLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 社群入口配置 工單4106
 *
 * 設定綁在總代身上，該線路下的所有代理共用同一份；下線代理只能檢視、不另外設定。
 */
class AgentSocialLinkService
{
    public function __construct(
        private readonly GeneralAgentScopeService $scopeService,
    ) {}

    /**
     * 解析「這次要操作哪個總代的設定」，委派 GeneralAgentScopeService（4102 抽出共用）。
     *
     * @throws ApiException 操作者不隸屬任何總代（系統帳號如 Admin／System）
     */
    public function resolveTargetAgentId(Agent $operator, ?int $requestedAgentId): int
    {
        return $this->scopeService->resolveTargetAgentId($operator, $requestedAgentId);
    }

    /** 操作者是否需要「選擇代理」，委派 GeneralAgentScopeService（4102 抽出共用） */
    public function isSuperAdmin(Agent $operator): bool
    {
        return $this->scopeService->isSuperAdmin($operator);
    }

    /** 某總代的設定清單（含停用者，後台編輯用） */
    public function list(int $agentId): Collection
    {
        return AgentSocialLink::query()
            ->where('agent_id', $agentId)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 整批覆寫某總代的設定。
     *
     * 前端送完整清單（含既有列的 id），此處以差集處理：
     * 不在清單內的既有列刪除、有 id 的更新、無 id 的新增。
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ApiException
     */
    public function save(int $agentId, array $items, int $adminId): void
    {
        if (count($items) > AgentSocialLink::MAX_PER_AGENT) {
            throw new ApiException('Exceeds the maximum of ' . AgentSocialLink::MAX_PER_AGENT . ' items');
        }

        DB::transaction(function () use ($agentId, $items, $adminId) {
            $keepIds = [];

            foreach ($items as $index => $item) {
                $payload = [
                    'agent_id' => $agentId,
                    'name' => (string) ($item['name'] ?? ''),
                    'link_type' => (int) ($item['link_type'] ?? AgentSocialLink::LINK_TYPE_URL),
                    'link_url' => $item['link_url'] ?? null,
                    'icon_url' => $item['icon_url'] ?? null,
                    // 前端可能只調整拖曳順序而未重算 sort，以陣列索引兜底
                    'sort' => (int) ($item['sort'] ?? $index),
                    'status' => (int) ($item['status'] ?? 1),
                    'admin' => $adminId,
                ];

                $id = (int) ($item['id'] ?? 0);

                if ($id > 0) {
                    // 限定 agent_id 才更新，避免帶別條線的 id 進來改到別人的資料
                    $affected = AgentSocialLink::query()
                        ->where('id', $id)
                        ->where('agent_id', $agentId)
                        ->update($payload);

                    if ($affected > 0) {
                        $keepIds[] = $id;

                        continue;
                    }
                }

                $keepIds[] = AgentSocialLink::query()->create($payload)->id;
            }

            // 清單中沒有的既有列＝使用者刪除的
            AgentSocialLink::query()
                ->where('agent_id', $agentId)
                ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
                ->delete();
        });
    }

    /** 前台用：某代理應顯示的社群入口（取其歸屬總代的啟用項目） */
    public function getFrontendLinks(Agent $agent): array
    {
        $topId = $agent->general_agent_id;

        if (!$topId) {
            return [];
        }

        return AgentSocialLink::query()
            ->where('agent_id', $topId)
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['name', 'link_type', 'link_url', 'icon_url'])
            ->map(fn (AgentSocialLink $row) => [
                'name' => $row->name,
                'link_type' => $row->link_type,
                'link_url' => $row->link_url,
                'icon_url' => $row->icon_url,
            ])
            ->all();
    }
}
