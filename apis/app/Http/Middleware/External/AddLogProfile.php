<?php

declare(strict_types=1);

namespace App\Http\Middleware\External;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 將中間件取得的上下文數據整合到 UserData
 */
class AddLogProfile
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::withContext([
            'profile' => [
                'domain' => $request->getHttpHost(),
                'route' => $request->path(),
                'ip' => $request->ip(),
            ],
        ]);

        return $next($request);
    }
}
