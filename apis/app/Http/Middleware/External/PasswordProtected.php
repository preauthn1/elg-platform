<?php

declare(strict_types=1);

namespace App\Http\Middleware\External;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 密碼保護中間件
 *
 * 用於保護特定路由，需要提供正確的密碼才能訪問
 * 密碼可透過請求參數 'password' 或 Header 'X-Access-Password' 傳遞
 */
class PasswordProtected
{
    /**
     * 處理請求
     *
     * @param  string|null  $password  設定的密碼（可從路由參數傳入）
     */
    public function handle(Request $request, Closure $next, ?string $password = [REDACTED] Response
    {
        // 從環境變數或參數中獲取密碼
        $expectedPassword = [REDACTED] ?? config('auth.route_password');

        if (empty($expectedPassword)) {
            // 如果未設定密碼，則拒絕訪問
            return response()->json([
                'success' => false,
                'message' => 'Route protection not configured',
            ], 403);
        }

        // 從請求中獲取密碼（支援參數或 Header）
        $inputPassword = [REDACTED]'password')
            ?? $request->header('X-Access-Password');

        // 驗證密碼
        if (empty($inputPassword) || $inputPassword !== $expectedPassword) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or missing password',
            ], 401);
        }

        return $next($request);
    }
}
