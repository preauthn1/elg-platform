<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentMenu;

/**
 * 代理選單樹與權限地圖（target/tickets#4422 自 API_Agent AgentMenuController::list、AgentPermissionController::getPermissionCodeMap 搬入）。
 *
 * 刻意的差異：
 * - 不快取，每次即時算（Max 2026-10-05）：與 apis 權限檢查同樣即時，舊後台改權限新後台立刻看得到；
 *   API_Agent 版快取 1 小時，且快取命中時跳過授權（任何人帶任意 agent_id 都讀得到別人的選單）
 * - 授權每次都做：只能讀自己或主帳號（HOST）整個子樹裡的代理
 * - 銀行卡管理過濾（target/tickets#4486）：不能使用會員銀行卡個資的代理
 *   （{@see AgentPermissionScopeService::canAccessMemberBankAccount()}），自己的選單樹與權限地圖
 *   一律濾掉 member_bank_account 與 member_bank_account_edit_permission 兩個節點（一起濾，不留孤兒節點）。
 *   查別人的選單樹（權限設定頁，operator != agentId）照實回傳不過濾：權限設定頁以這棵樹為底存檔，
 *   濾掉會讓存檔時把目標既有的這兩個權限刪掉
 *
 * 輸出結構與 API_Agent 完全相同（前端選單、權限樹直接沿用）。is_exclude_tag_invisible 在 API_Agent
 * 只影響快取 key、不過濾任何東西，這裡照收不用；隱藏 tag_invisible 由前端自己做。
 */
class AgentMenuTreeService
{
    /** symbol 為此值或 sort 為此值的選單不是可點的頁面（按鈕／隱藏權限節點） */
    private const string INVISIBLE_SYMBOL = 'tag_invisible';

    private const int INVISIBLE_SORT = -999;

    public function __construct(
        private readonly AgentPermissionScopeService $scope,
    ) {}

    /**
     * 某代理的生效選單樹。
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(Agent $operator, int $agentId): array
    {
        if ($operator->id !== $agentId && !$this->scope->isInScope($this->scope->hostId($operator), $agentId)) {
            throw new ApiException(__('error_message.9003'));
        }

        $effectiveIds = $this->scope->effectiveMenuIds($agentId);
        if ($effectiveIds === []) {
            return [];
        }
        $actionMap = $this->scope->effectiveMenuActionMap($agentId);
        // 只過濾看自己的選單樹；查別人（權限設定頁）照實回傳，見 class docblock
        $hiddenCodes = $operator->id === $agentId ? $this->hiddenMenuCodes($operator) : [];

        $rows = AgentMenu::query()
            ->whereIn('id', $effectiveIds)
            ->where('status', 1)
            ->when($hiddenCodes !== [], fn ($query) => $query->whereNotIn('code', $hiddenCodes))
            ->orderBy('parent')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'parent', 'code', 'name', 'url', 'symbol', 'sort'])
            ->map(fn (AgentMenu $m): array => [
                'id' => $m->id,
                'parent' => $m->parent,
                'code' => $m->code,
                'name' => $m->name,
                'url' => $m->url,
                'sort' => $m->sort,
                'symbol' => $m->symbol,
                'action' => $actionMap[$m->id] ?? '',
                'is_url_menu' => $m->symbol !== self::INVISIBLE_SYMBOL && (int) $m->sort !== self::INVISIBLE_SORT,
            ])
            ->all();

        return $this->markSecondLevel($this->buildTree($rows));
    }

    /**
     * 權限地圖 {code: true}，前端用來判斷按鈕顯示。照 API_Agent：不過濾選單 status；沒有任何權限回空陣列。
     *
     * @return array<string, bool>
     */
    public function codeMap(Agent $operator): array
    {
        $ids = $this->scope->effectiveMenuIds($operator->id);
        if ($ids === []) {
            return [];
        }

        $hiddenCodes = $this->hiddenMenuCodes($operator);

        return AgentMenu::query()->whereIn('id', $ids)
            ->when($hiddenCodes !== [], fn ($query) => $query->whereNotIn('code', $hiddenCodes))
            ->pluck('code')
            ->mapWithKeys(fn ($code): array => [(string) $code => true])
            ->all();
    }

    /**
     * 代理自己的選單樹與權限地圖要濾掉的選單代碼：不能使用會員銀行卡個資時濾掉銀行卡管理兩個節點（target/tickets#4486）。
     *
     * @return array<int, string>
     */
    private function hiddenMenuCodes(Agent $agent): array
    {
        return $this->scope->canAccessMemberBankAccount($agent) ? [] : AgentPermissionScopeService::MEMBER_BANK_ACCOUNT_MENU_CODES;
    }

    /**
     * 依 parent 組樹：父節點在集合內就掛到它的 submenu，否則放根層（有子選單權限、沒有父選單權限時子選單升到根層）。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $rows): array
    {
        $nodes = [];
        foreach ($rows as $row) {
            $nodes[$row['id']] = $row;
        }

        $tree = [];
        foreach (array_keys($nodes) as $id) {
            $parent = $nodes[$id]['parent'];
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['submenu'][] = &$nodes[$id];
            } else {
                $tree[] = &$nodes[$id];
            }
        }

        // 解除參照，避免回傳後被意外改動
        return json_decode((string) json_encode($tree), true) ?? [];
    }

    /**
     * 第二層節點補 is_any_url_submenu：它的第三層子節點有任何一個是可點頁面時為 true。
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, array<string, mixed>>
     */
    private function markSecondLevel(array $tree): array
    {
        foreach ($tree as &$root) {
            if (empty($root['submenu'])) {
                continue;
            }
            foreach ($root['submenu'] as &$second) {
                $second['is_any_url_submenu'] = array_any(
                    $second['submenu'] ?? [],
                    static fn (array $third): bool => (bool) $third['is_url_menu'],
                );
            }
            unset($second);
        }
        unset($root);

        return $tree;
    }
}
