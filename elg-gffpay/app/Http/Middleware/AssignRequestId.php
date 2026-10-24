<?php

namespace App\Http\Middleware;

use App\Enums\General\ContextKeyEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // 針對每次請求生成一個批號, 紀錄在歷程所有日誌, 方便在 ELK 使用批號查詢
        $requestId = (string) Str::uuid();

        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, $requestId);

        Log::shareContext(['request_id' => $requestId]);

        return $next($request);
    }
}
