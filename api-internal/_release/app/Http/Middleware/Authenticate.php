<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Helpers\Common;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            $language = $request->header('Accept-Language');
            // 設定語系
            if (\App::getLocale() != $language && $language != '') {
                \App::setLocale($language);
            }

            // 2. 檢查TOKEN
            $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
            $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
            if (is_object($access_token)) {
                // 3. 檢查用戶是否啓用
                $user = Agent::where('id', $access_token->agent_id)->where('status', 1)->first();
                if (is_object($user)) {
                    // TODO 4. 檢查用戶是否有權限
                    // $this->hasPermission($request->path(), $access_token->agent_id);
                    // 5.解密param
                    // 加上通用資料
                    $request->merge(array(
                        'ID' => $access_token->agent_id,
                        'HOST' => ($user->host_id != 0) ? $user->host_id : $user->id, //主要的帳號，如果是HOST ID不是0就是子帳號，他主要的帳號為HOST ID，如果是0，他主要的帳號是他自己
                        'PARENT' => $user->parent,
                        'CODE' => 1000,
                        'TOKEN' => $token,
                        'LANGUAGE' => Common::getLanguageId($language)
                    ));

                    return $next($request);
                }
            }
            return response('Unauthorized.', 401);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
