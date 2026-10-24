<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\RoleEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;

/**
 * 「設定綁總代」類功能共用的操作範圍解析 工單4102
 *
 * 從 4106 社群入口配置（AgentSocialLinkService）抽出，供社群入口與 4102 SEO 元數據共用：
 * super admin（含其子帳號）先選總代再操作；其餘代理一律解析成自己歸屬的總代，
 * 送來的 agent_id 不予採信。
 */
class GeneralAgentScopeService
{
    /**
     * 解析「這次要操作哪個總代的設定」。
     *
     * super admin（含其子帳號）可指定任一總代；其餘代理一律取自己歸屬的總代，
     * 送來的 agent_id 不予採信——否則等同讓下線改別條線的設定。
     *
     * @throws ApiException 操作者不隸屬任何總代（系統帳號如 Admin／System）
     */
    public function resolveTargetAgentId(Agent $operator, ?int $requestedAgentId): int
    {
        if ($this->isSuperAdmin($operator)) {
            $agentId = $requestedAgentId ?: null;

            if (!$agentId) {
                throw new ApiException('Please specify an agent');
            }

            $this->assertGeneralAgent($agentId);

            return $agentId;
        }

        $ownTopId = $operator->general_agent_id;

        if (!$ownTopId) {
            throw new ApiException('Operator does not belong to any general agent');
        }

        return (int) $ownTopId;
    }

    /**
     * 操作者是否需要「選擇代理」——只有 super admin 及其子帳號要選，
     * 其餘代理的歸屬總代由身分決定，前端據此隱藏下拉。
     */
    public function isSuperAdmin(Agent $operator): bool
    {
        return $operator->id === 1 || (int) $operator->host_id === 1;
    }

    /**
     * 總代下拉選項（啟用中的總代，依帳號排序）。
     *
     * 僅 super admin 需要——其餘代理的歸屬總代由身分決定、無從選擇，是否開放由呼叫端判斷。
     *
     * @return array<int, array{id: int, username: string}>
     */
    public function generalAgentOptions(): array
    {
        return Agent::query()
            ->where('role_id', RoleEnum::GENERAL_AGENT->value)
            ->where('status', 1)
            ->orderBy('username')
            ->get(['id', 'username'])
            ->map(fn (Agent $a) => [
                'id' => (int) $a->id,
                'username' => $a->username,
            ])
            ->all();
    }

    /** @throws ApiException 指定的 agent_id 不是總代 */
    public function assertGeneralAgent(int $agentId): void
    {
        $exists = Agent::query()
            ->where('id', $agentId)
            ->where('role_id', RoleEnum::GENERAL_AGENT->value)
            ->exists();

        if (!$exists) {
            throw new ApiException('The specified agent is not a general agent');
        }
    }
}
