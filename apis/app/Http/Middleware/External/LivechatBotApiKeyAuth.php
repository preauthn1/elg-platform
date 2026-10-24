<?php

declare(strict_types=1);

namespace App\Http\Middleware\External;

use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Models\LivechatBot;
use App\Services\LivechatBot\LivechatBotContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livechat Bot API Key 驗證中間件
 *
 * 從 `Authorization: Bearer <key>` 取出 token，驗證是否對應一筆啟用中的
 * livechat_bots 紀錄；通過後將對應的 LivechatBotContext 綁入容器，
 * 並把 bot 名稱與 agent_id 寫入 log context。
 */
class LivechatBotApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $inputApiKey = [REDACTED];

        if (!is_string($inputApiKey) || $inputApiKey === '') {
            return $this->unauthorized();
        }

        $bot = LivechatBot::query()
            ->where('api_key', $inputApiKey)
            ->where('is_active', true)
            ->first();

        if ($bot === null) {
            return $this->unauthorized();
        }

        // 從容器拿 singleton 並寫入 bot，下游 Service constructor 注入時會取到同一份。
        // singleton 由 AppServiceProvider 註冊。
        $context = app(LivechatBotContext::class);
        $context->setBot($bot);

        // 補上 log 上下文，便於追蹤是哪個 bot 觸發的請求
        Log::withContext([
            'livechat_bot_name' => $context->name(),
            'livechat_bot_agent_id' => $context->agentId(),
        ]);

        // bot 為 machine-to-machine 請求，不依 Accept-Language 而採固定預設語系，
        // 供下游讀取 ContextKeyEnum::LANGUAGE_ID 的 service（如 AgentService::getAgentSettings）使用。
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::EN->value);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'code' => 401,
            'message' => 'Invalid or missing API key',
        ], 401);
    }
}
