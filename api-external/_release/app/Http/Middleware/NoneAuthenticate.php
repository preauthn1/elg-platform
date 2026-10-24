<?php

namespace App\Http\Middleware;

use Closure;
use App;
use App\Helpers\Common;
use DB;

class NoneAuthenticate
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
            $response = $next($request);
            $result = json_decode(json_encode($response, true), JSON_UNESCAPED_UNICODE);
            $log = [
                'request' => $request->all(),
                'response' => $result
            ];
            Common::log($log, 'response');
            return $response;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}