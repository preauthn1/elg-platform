<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Models\Country;
use App\REDIS;

/**
 * @group Dropdown
 */
class CountryController extends Controller
{
    /**
     * countries
     * - 國家列表(Header不需要token)
     *
     * @bodyParam domain string 網域 Example: example.invalid
     * @bodyParam agent_id integer 代理ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台灣",
     *            "country_code": "國碼,ex:886",
     *            "phone_rule": "手機驗證正規式,ex:xxx"
     *        },
     *         {
     *            "id": 2,
     *            "code": "HK",
     *            "name": "香港",
     *            "country_code": "60",
     *            "phone_rule": "xxx"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = ($request->ID != '')
                ? array('country', 'list', 'member', $request->ID)
                : (
                    ($request->agent_id != '')
                        ? array('country', 'list', 'agent_id', $request->agent_id)
                        : array('country', 'list', 'domain', $request->domain)
                );

            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = ($request->ID != '')
                    ? DB::table('members as ad')
                    : DB::table('agent_domains as ad');

                $model = $model->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'ad.agent_id');
                    if ($request->agent_id != '') {
                        $join->where('a.id', $request->agent_id);
                    }
                })
                    ->join('sms_gateway_infos as sgi', function ($join) {
                        $join->on('sgi.sms_gateway_group_id', 'a.sms_gateway_group_id')
                            ->where('sgi.status', 1);
                    })
                    ->leftJoin('sms_gateway_info_countries as sgic', function ($join) {
                        $join->on('sgic.sms_gateway_info_id', 'sgi.id')
                            ->where('sgic.status', 1);
                    })
                    ->join('countries as c', function ($join) {
                        $join->on('c.id', 'sgic.country_id')
                            ->where('c.status', 1);
                    })
                    ->leftJoin('country_names as cn', function ($join) use ($request) {
                        $join->on('cn.country_id', 'c.id')
                            ->where('cn.language_id', (isset($request->LANGUAGE) ? $request->LANGUAGE: 1));
                    })
                    ->where('ad.status', 1);

                if ($request->ID != '') {
                    $model = $model->where('ad.id', $request->ID);
                } elseif ($request->agent_id != '') {
                    $model = $model->where('ad.agent_id', $request->agent_id);
                } else {
                    $model = $model->where('ad.domain', $request->domain);
                }

                $data = $model->groupBy('c.id')
                    ->orderBy('c.id', 'ASC')
                    ->select(
                        'c.id',
                        'c.code',
                        DB::raw('IFNULL(cn.name, c.name) as name'),
                        'c.country_code',
                        'c.phone_rule'
                    )
                    ->get();

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
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}
