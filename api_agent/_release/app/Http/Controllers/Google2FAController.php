<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\AgentAuthentication;
use App\REDIS;
use Validator;
use DB;
use Exception;

class Google2FAController extends Controller
{
    /**
     * 2fa/auth
     * - 登入二階段驗證
     *
     * @bodyParam agent_id integer required 代理ID Example: 123
     * @bodyParam secret string required 代理密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     * }
     */
    public function auth(Request $request)
    {
        try {
            $agent_id = $request->agent_id;
            $valid = $this->auth2fa($agent_id, $request->secret);
            if ($valid != true) {
                return response()->json([
                    'code' => 1098,
                    'message' => __('error_message.1098')
                ]);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 2fa/image
     * - 取得二階段驗證QRCode
     *
     * @bodyParam agent_id integer required 代理ID Example: 123
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "image": "<svg xmlns=....>...</svg>",
     *        "secret": "I3WXGHCG5D3NIAAK"
     *    }
     * }
     */
    public function getImage(Request $request)
    {
        try {
            $key = array(
                'agent_auth',
                'google',
                $request->agent_id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $agent = Agent::find($request->agent_id);
                $org = Organization::find($agent->organization_id);
                $auth = AgentAuthentication::where('agent_id', $request->agent_id)
                    ->where('status', 1)
                    ->first();

                // Initialise the 2FA class
                $google2fa = app('pragmarx.google2fa');

                if (is_object($auth) && $auth->secret != '') {
                    $google_2fa_secret = [REDACTED];
                    $google_2fa_image = $auth->image;
                } else {
                    $google_2fa_secret = [REDACTED];
                    $google_2fa_image = $google2fa->getQRCodeInline(
                        $org->name,
                        $agent->username,
                        $google_2fa_secret,
                        400  //QRCode圖片大小
                    );

                    //因為回來的圖只能設定固定值, 沒辦法設定100%, 所以直接用文字取代
                    $google_2fa_image = str_replace(
                        'version="1.1" width="400" height="400"',
                        'version="1.1" width="100%"',
                        $google_2fa_image
                    );

                    $data = array();
                    $data['agent_id'] = $request->agent_id;
                    $data['type'] = 1;
                    $data['secret'] = $google_2fa_secret;
                    $data['image'] = $google_2fa_image;
                    $data['status'] = 1;
                    $data['admin'] = 0;
                    AgentAuthentication::create($data);
                }

                $data = array();
                $data['image'] = $google_2fa_image;
                $data['secret'] = $google_2fa_secret;
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 2fa/reset
     * - 重置二階段驗證
     *
     * @bodyParam agent_id integer required 代理ID Example: 123
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function reset2fa(Request $request)
    {
       try {
           $result = AgentAuthentication::where('agent_id', $request->agent_id)
               ->where('status', 1)
               ->update(['status' => 0]);
           // 刪除Redis中相關的資料
           $redis = new REDIS();
           $redis->delete(array(
               'agent_auth',
               'google',
               $request->agent_id
           ));
       } catch (\Illuminate\Database\QueryException $e) {
           $info = $e->errorInfo;
           return response()->json([
               'code' => $info[0],
               'message' => $info[2]
           ]);
       } catch (Exception $exception) {
           return response()->json([
               'code' => $exception->getCode(),
               'message' => $exception->getMessage()
           ]);
       }
    }

    public function auth2fa($agent_id, $secret)
    {
        try {
            $auth = AgentAuthentication::where('agent_id', $agent_id)
                ->where('status', 1)
                ->first();
            $google2fa = app('pragmarx.google2fa');
            return $google2fa->verifyKey($auth->secret, $secret);
        } catch (Exception $exception) {
            return false;
        }
    }
}