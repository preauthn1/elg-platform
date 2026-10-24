<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend\ThirdParty;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Helpers\ApiResponse;
use App\Models\Agent;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Special middleware for third party requests
 */
class ThirdPartyAuthMember
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!isset($request->username) && !isset($request->from)) {
            return ApiResponse::error(
                message: __('error_message.1005'),
                code: HttpCode::HTTP_NOT_ACCEPTABLE->value,
            );
        }

        $member = null;
        $agent = null;

        if (isset($request->username)) {
            $member = Member::with('agent')
                ->where('username', $request->username)
                ->where('status', 1)
                ->first();

            if (!$member) {
                return ApiResponse::error(
                    message: __('error_message.1005'),
                    code: HttpCode::HTTP_NOT_ACCEPTABLE->value,
                );
            }

            $agent = $member->agent;
        } else {
            $agent = Agent::where('username', $request->from)->first();

            if (!$agent) {
                return ApiResponse::error(
                    message: __('error_message.1005'),
                    code: HttpCode::HTTP_NOT_ACCEPTABLE->value,
                );
            }
        }

        Context::addHidden(ContextKeyEnum::MEMBER->value, $member?->makeHidden('agent'));
        Context::addHidden(ContextKeyEnum::AGENT->value, $agent);
        $ip = Context::getHidden(ContextKeyEnum::IP->value);

        Log::withContext([
            'profile' => [
                'agent_id' => $agent->id,
                'member_id' => $member->id ?? null,
                'username' => $member->username ?? null,
                'ip' => $ip,
            ],
        ]);

        return $next($request);
    }
}
