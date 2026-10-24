<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\REDIS;
use Validator;
use DB;

/**
 * @group AgentDomain
 */
class AgentDomainController extends Controller
{

    /**
     * frontend_domain
     * - 前台網域(app用)
     *
     * @bodyParam agent_id integer required 代理編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  "example.invalid"
     * }
     */
    public function frontendDomain(Request $request)
    {
        try {
            // Redis 相同的條件10分鐘更新一次
            $key = array(
                'frontend_domain',
                'list',
                $request->agent_id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = DB::table('agent_domains as ad')
                    ->where('ad.agent_id', $request->agent_id)
                    ->where('ad.status', 1)
                    ->where('ad.type', 0)
                    ->orderBy('ad.sort', 'desc')
                    ->select(
                        'domain'
                    )
                    ->first();
                $data = $model->domain;
                $redis->set($key, $data, 600);
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
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * getAgentCodeByDomain
     * - 用Domain尋找下層代理
     *
     * @bodyParam domain string required 域名 Example: example.invalid
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  {
     *              "has_open" : 1
     *              "agent_list" : [
     *                               {
     *                                  "nickname" : "aaa",
     *                                  "code"     : "ABC123"
     *                 }
     *              ]
     *          }
     * }
     */
    public function getAgentCodeByDomain(Request $request)
    {
        try {
            $agent_domain = DB::table('agent_domains')->where('domain', $request->domain)->first();
            $data = array();
            $data['has_open'] = 0;
            $data['agent_list'] = array();
            if (is_object($agent_domain)) {
                $info = json_decode($agent_domain->info);
                if ($info->has_auto_show_agent_code != 0) {
                    $level = isset($info->agent_list_level) ?  $info->agent_list_level : 1;
                    $data['has_open'] = 1;
                    $sub_query = DB::table('agents as a')
                        ->where('hierarchy_path', 'like', '/1/' . $agent_domain->agent_id . '/%')
                        ->where('host_id', 0)
                        ->select(
                            'id',
                            'username',
                            'nickname',
                            'code',
                            DB::raw(
                                "(LENGTH(REPLACE(hierarchy_path, '/1/" . $agent_domain->agent_id . "',''))" .
                                "- LENGTH(REPLACE(REPLACE(hierarchy_path, '/1/" . $agent_domain->agent_id . "',''),'/',''))) as level"
                            )
                        );
                    $query = DB::table(DB::raw('(' . $sub_query->toSql(). ') as z'))
                        ->mergeBindings($sub_query)
                        ->where('z.level', $level)
                        ->select(
                            'z.username as nickname',
                            'z.code as code'
                        )
                        ->get();
                    if(count($query) > 0) {
                        $data['agent_list'] = $query;
                    }
                }
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
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }


    public function getAlternateDomain(Request $request)
    {
        try {
            $data = DB::table('params')
                ->where('type', 'alternate_domain')
                ->where('status', 1)
                ->orderBy('sort', 'DESC')
                ->pluck('parameter');

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
            ]);
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
