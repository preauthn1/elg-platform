<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class DetectIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = Common::getIp();

        Context::addHidden(ContextKeyEnum::IP->value, $ip);

        return $next($request);
    }
}
