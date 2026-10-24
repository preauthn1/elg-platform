<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class HasDomain
{
    /**
     * 若 HasMember 中沒有將代理放在上下文
     * 且請求中有 domain 參數
     * 透過 domain 查詢代理商放在上下文
     *
     * @throws ApiException
     */
    public function handle(Request $request, Closure $next): Response
    {

        if ($request->has('domain') === false) {
            return $next($request);
        }

        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        if (!$agent) {
            $filter = [
                'status' => 1,
                'domain' => $request->input('domain'),
            ];

            $agentDomain = AgentDomain::where($filter)->first();

            if (!$agentDomain) {
                throw new ApiException(__('error_message.1213'));
            }

            Context::addHidden(ContextKeyEnum::AGENT->value, $agentDomain->agent);
        }

        return $next($request);
    }
}
