<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bulletin;
use App\REDIS;
use Validator;

/**
 * @group Bulletins
 */
class BulletinController extends Controller
{

    /**
     * bulletin/list
     * - 公告列表
     *
     * @bodyParam type_id integer 類型編號 Example: 1
     * @bodyParam country_id integer 國家編號 Example: 1
     * @bodyParam agent_id integer 代理編號 Example: 1
     * @bodyParam title string 公告標題 Example: Hello
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "type_id": "類型編號,ex:1",
     *            "title": "標題,ex:Hello World",
     *            "content": "內容,ex:Test"
     *          },
     *          {
     *            "id": "系統編號,ex:2",
     *            "type_id": "類型編號,ex:1",
     *            "title": "標題,ex:Hello World",
     *            "content": "內容,ex:Test"
     *          }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Bulletin();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'bulletin',
                'list',
                $request->LANGUAGE,
                $request->type_id,
                $request->country_id,
                $request->agent_id,
                $request->title,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'bulletin',
                'list',
                $request->LANGUAGE,
                $request->type_id,
                $request->country_id,
                $request->agent_id,
                $request->title,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                if ($request->type_id) {
                    $model->where('type_id', $request->type_id);
                }
                if ($request->country_id) {
                    $model->where('country_id', $request->country_id);
                }
                if ($request->agent_id) {
                    $model->where('agent_id', $request->agent_id);
                }
                if ($request->title) {
                    $model->where('title', 'like', '%' . $request->title . '%');
                }
                $data = $model->select('id', 'title', 'content')
                    ->where('language_id', $request->LANGUAGE)
                    ->where('start_time', '<=', date('Y-m-d H:i:s'))
                    ->where('end_time', '>=', date('Y-m-d H:i:s'));
                $total = $data->count();
                $data = $data->orderBy('start_time', 'desc')
                    ->orderBy('id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                $redis->set($key, $data);
                $redis->set($total_key, $total);
            } else {
                $data = $redis->get($key);
                $total = $redis->get($total_key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
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
     * bulletin/show
     * - 公告明細
     *
     * @bodyParam id integer required 系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *            "id": "系統編號,ex:1",
     *            "type_id": "類型編號,ex:1",
     *            "title": "標題,ex:Hello World",
     *            "content": "內容,ex:Test"
     *          }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Bulletin();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'bulletin',
                'show',
                $request->LANGUAGE,
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('id', 'title', 'content', 'end_time')
                    ->where('language_id', $request->LANGUAGE)
                    ->where('start_time', '<=', date('Y-m-d H:i:s'))
                    ->where('end_time', '>=', date('Y-m-d H:i:s'))
                    ->First();
                if ($data != '') {
                    $second = strtotime($data['end_time']) - date('U');
                    $redis->set($key, $data);
                    // 設定到期時間
                    $redis->expire($key, $second);
                } else {
                    return response()->json([
                        'code' => 1601,
                        'message' => __('error_message.1601')
                    ]);
                }
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1608'),
            'id.digits_between' => __('error_message.1606'),

            'type_id.digits_between' => __('error_message.1602'),

            'country_id.digits_between' => __('error_message.2022'),

            'agent_id.digits_between' => __('error_message.2018'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
