<?php


namespace App\Http\Middleware;

use Closure;

class PerformanceLogger
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }

    public function terminate($request, $response) {
        $usage_ms = intval((microtime(true) - LARAVEL_START) * 1000);
        $message = sprintf("%s %s execute time %d ms", $_SERVER['REQUEST_METHOD'], $request->fullUrl(), $usage_ms);
        if($usage_ms > 3000) {
            \Log::warning($message, $request->all());
        } else {
            \Log::debug($message, $request->all());
        }

    }
}