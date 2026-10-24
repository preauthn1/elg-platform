<?php

namespace App\Http\Middleware;

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

        // addHidden: 傳遞到隊列，但不會自動附加到日誌
        Context::addHidden('REQUEST_ID', $requestId);

        Log::shareContext(['request_id' => $requestId]);

        return $next($request);
    }
}
