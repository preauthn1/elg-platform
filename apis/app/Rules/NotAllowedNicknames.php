<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Agent;
use App\Models\AgentPersonalSetting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;

class NotAllowedNicknames implements ValidationRule
{
    protected Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 從資料庫取得代理個人配置
        $querySettings = $this->getAgentPersonalSetting($this->agent, 92001);

        // 預設不支援的暱稱
        $invalidNicknames = ['customer', 'service', 'vworld', 'agent', 'a g e n t', 'v w o r l d', 's e r v i c e', '代理', '客服'];

        if (is_object($querySettings)) {
            $settingValues = explode(',', $querySettings->value);
            $invalidNicknames = array_diff($settingValues, ['']); // 移除空值
        }

        // 檢查暱稱是否包含不允許的字彙
        foreach ($invalidNicknames as $word) {
            if (str_contains(strtolower($value), $word)) {
                $fail(__('error_message.not allowed words', ['attribute' => $attribute, 'word' => $word]));
            }
        }
    }

    public function getAgentPersonalSetting(Agent $agent, $settingId)
    {
        $agentIDs = $agent->hierarchy_array;

        $query = $this->searchAgentPersonalSetting($agentIDs, [$settingId]);

        $count = count($query);

        if ($count <= 0) {
            return null;
        }

        return $query->first();
    }

    /**
     * 查詢代理是否有個人設定
     */
    private function searchAgentPersonalSetting($agentIds, $settingIds): Collection
    {
        return AgentPersonalSetting::query()
            ->whereIn('agent_id', $agentIds)
            ->whereIn('setting_id', $settingIds)
            ->orderBy('agent_id')
            ->get();
    }
}
