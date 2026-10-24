<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Models\LivechatBot;
use App\Models\Member;
use RuntimeException;

/**
 * 持有當次請求所對應的 LivechatBot model。
 *
 * 採可變 singleton 設計：以容器 singleton 方式註冊（無建構參數），
 * 由 LivechatBotApiKeyAuth middleware 在驗證通過後呼叫 {@see setBot()} 寫入。
 *
 * 採此設計的原因是 Laravel 在 dispatch route 時會 reflection 解析 controller 與
 * 其相依鏈，會比 middleware 早建構 service。若把 LivechatBot 放在 constructor
 * 注入，下游 service 拿到的會是空 model，導致跨 agent guard 永遠誤判。
 */
class LivechatBotContext
{
    private ?LivechatBot $bot = null;

    public function setBot(LivechatBot $bot): void
    {
        $this->bot = $bot;
    }

    public function id(): int
    {
        return (int) $this->bot()->id;
    }

    public function agentId(): int
    {
        return (int) $this->bot()->agent_id;
    }

    public function name(): string
    {
        return (string) $this->bot()->name;
    }

    public function vipUrl(): ?string
    {
        $url = $this->bot()->vip_url;

        return $url === null ? null : (string) $url;
    }

    /**
     * 依 username 查 member，限定在此 bot 綁定 agent 的代理線下；
     * 查無或跨 agent 則回 null（由 caller 決定是否轉為 404，避免洩漏存在性）。
     *
     * 用於 livechat bot 端點以 path param `{username}` 取代舊 `user-id` 後，
     * 統一 7 個 service 的 member 取得方式並一次完成跨租戶隔離。
     */
    public function findMemberByUsername(string $username): ?Member
    {
        $botAgent = $this->bot()->agent;
        if ($botAgent === null) {
            return null;
        }

        return Member::with('agent')
            ->where('username', $username)
            ->whereHas('agent', fn ($q) => $q->where('hierarchy_path', 'like', $botAgent->hierarchy_path . '%'))
            ->first();
    }

    private function bot(): LivechatBot
    {
        if ($this->bot === null) {
            throw new RuntimeException('LivechatBotContext not initialized; ensure LivechatBotApiKeyAuth middleware has run.');
        }

        return $this->bot;
    }
}
