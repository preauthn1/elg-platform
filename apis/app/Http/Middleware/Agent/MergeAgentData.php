<?php

declare(strict_types=1);

namespace App\Http\Middleware\Agent;

use App\Data\AgentData;
use App\Enums\General\ContextKeyEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 將中間件取得的上下文數據整合到 AgentData
 */
class MergeAgentData
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = [REDACTED];
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        $ip = Context::getHidden(ContextKeyEnum::IP->value);

        $agentData = AgentData::from([
            'token' => $token,
            'agent' => $agent,
            'languageId' => $languageId,
            'ip' => $ip,
        ]);

        Context::addHidden(ContextKeyEnum::AGENT_DATA->value, $agentData);

        Log::withContext([
            'profile' => [
                'domain' => $request->getHttpHost(),
                'route' => $request->path(),
                'agent_id' => $agent->id ?? null,
                'agent_username' => $agent->username ?? null,
                'language_id' => $languageId,
                'ip' => $ip,
            ],
        ]);

        return $next($request);
    }
}
