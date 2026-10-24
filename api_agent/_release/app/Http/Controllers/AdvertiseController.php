<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\Models\Advertise;
use App\Models\AdvertiseImage;
use App\Models\AdvertiseLanguage;
use App\Models\Agent;
use App\Helpers\SQLHelper;
use App\REDIS;
use Validator;
use Exception;
use DB;

class AdvertiseController extends Controller
{
    /**
     * advertise/image
     * -廣告圖片清單
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
    */
    public function getImage(Request $request)
    {
        try {
            $model = new AdvertiseImage();
            $agent = Agent::where('id', $request->HOST)->first();
            $model = DB::table('advertise_images as ai')
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'ai.admin')
                        ->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
                });

            $total = $model->count();
            $data = $model->orderBy('ai.updated_at', 'DESC')
                ->select(
                    'ai.*'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                //'sql' => SQLHelper::getQueries($data)
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
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
     * advertise/list
     * -廣告列表
     *
     * @bodyParam path_id integer 類型編號 Example: 1
     * @bodyParam path_name string 類型名稱 Example: index
     * @bodyParam start_time date  開始日期 Example: 2019-01-01 00:00:00
     * @bodyParam end_time date  結束日期 Example: 2019-01-31 23:59:59
     * @bodyParam language_id integer 語系編號 Example: 1
     * @bodyParam country_id integer 國家編號 Example: 1
     * @bodyParam agent_id integer 營運商編號 Example: 1
     * @bodyParam status boolean 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "版位系統編號,ex:1",
     *            "name": "版位名稱,ex:index_top",
     *            "column": "欄位數量,ex:2",
     *            "width": "圖片寬度,ex:640",
     *            "height": "圖片高度,ex:300",
     *            "status": "狀態0:停用1:啟用,ex:1",
     *            "images":  [
     *                        {
     *                            "id": "系統編號,ex:1",
     *                            "language_id": "語系系統編號,ex:1",
     *                            "language_name": "語系名稱,ex:English",
     *                            "country_id": "國家系統編號,ex:0",
     *                            "agent_id": "營運商系統編號,ex:0",
     *                            "image": "圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                            "image_pc": "PC版圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                            "url": "連結網址,ex:http://xxx.xxx.xxx",
     *                            "game_id": "遊戲編號,ex:1",
     *                            "game_type_id": "遊戲類型編號,ex:1",
     *                            "vendor_id": "遊戲商編號,ex:1",
     *                            "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *                            "end_time": "結束時間,ex:2019-04-30 00:00:00",
     *                            "sort": "排序,ex:1"
     *                        }
     *                    ]
     *          }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Advertise();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'));

            $agent = Agent::where('id', $request->HOST)->first();

            $model = DB::table('advertises AS ad')
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'ad.agent_id')
                        ->where(function($q) use ($agent) {
                           $q->where('a.id', $agent->id)
                               ->orWhere('a.hierarchy_path', 'like', $agent->hierarchy_path . '/%');
                        });
                })
                ->join('advertisement_paths AS ap', 'ap.id', 'ad.advertisement_path_id')
                ->leftJoin('advertisement_path_names AS apn', function ($join) use ($request) {
                    $join->on('apn.advertisement_path_id', 'ap.id')
                        ->where('apn.language_id', $request->LANGUAGE);
                })
                ->leftjoin('game_types as gt', function ($join) {
                    $join->on('gt.id', 'ad.content')
                        ->where('ad.type', 1);
                })
                ->leftjoin('games as g', function ($join) {
                    $join->on('g.id', 'ad.content')
                        ->where('ad.type', 2);
                })
                ->leftjoin('vendors as v', function ($join) {
                    $join->on('v.id', 'ad.content')
                        ->where('ad.type', 3);
                });
                //->where('ad.start_time', '>=', $start_time)
                //->where('ad.end_time', '<=', $end_time);
                if (count($request['path_id']) > 0) {
                    $model = $model->whereIn('ad.advertisement_path_id', $request['path_id']);
                }
                $data = $model->select(
                    'ad.id',
                    'ad.advertisement_path_id',
                    DB::raw('IFNULL(apn.name, ap.name) AS advertisement_path_name'),
                    'ad.agent_id',
                    DB::raw('date_add(ad.start_time, INTERVAL 8 HOUR) AS start_time'),
                    DB::raw('date_add(ad.end_time, INTERVAL 8 HOUR) AS end_time'),
                    'ad.type',
                    DB::raw("CASE ad.type WHEN 1 THEN 'game_type' WHEN 2 THEN 'game' WHEN 3 THEN 'vendor' WHEN 4 THEN 'external' WHEN 5 THEN 'internal' ELSE 'not_link' END AS link_type"),
                    DB::raw("CASE ad.type WHEN 1 THEN gt.name WHEN 2 THEN g.name WHEN 3 THEN v.name WHEN 4 THEN ad.content WHEN 5 THEN ad.content END AS content"),
                    'ad.open_window',
                    'ad.sort',
                    'ad.status',
                    'ad.remark',
                    'ad.admin',
                    'a.username'
                )
                ->get();

            foreach ($data as $row) {
                $detail = DB::table('advertise_languages as al')
                    ->join('advertise_images as ai', 'ai.id', 'al.advertise_image_id')
                    ->join('languages as l', 'l.id', 'al.language_id')
                    ->where('al.advertise_id', $row->id)
                    ->select(
                        'l.name',
                        'al.language_id',
                        'al.advertise_image_id',
                        'ai.image_url'
                    )
                    ->get();
                $row->detail = $detail;
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);

            // 先取位置，再取內容
            $model = new AdvertisementPath();
            if ($request->path_id != '') {
                $model = $model->where('id', $request->path_id);
            }
            if ($request->path_name != '') {
                $model = $model->where('name', 'like', '%' . $request->path_name . '%');
            }
            if (isset($request->status)) {
                $model = $model->where('status', $request->status);
            }
            $data = $model->select('id', 'name', 'column', 'width', 'height', 'status')
                ->get();
            // 取圖片
            foreach ($data as $_key => $row) {
                $model_image = DB::table('advertisements as a')
                    ->join('languages as l', 'l.id', 'a.language_id');
                if ($request->start_time != '') {
                    $model_image = $model_image->where('a.end_time', '>=', $request->start_time);
                }
                if ($request->end_time != '') {
                    $model_image = $model_image->where('a.start_time', '<=', $request->end_time);
                }
                if ($request->language_id != '') {
                    $model_image = $model_image->where('a.language_id', $request->language_id);
                }
                if ($request->country_id != '') {
                    $model_image = $model_image->where('a.country_id', $request->country_id);
                }
                if ($request->agent_id != '') {
                    $model_image = $model_image->where('a.agent_id', $request->agent_id);
                }
                $images = $model_image->where('a.path_id', $row->id)
                    ->orderBy('a.sort', 'asc')
                    ->select(
                        'a.id',
                        'a.language_id',
                        'l.name as language_name',
                        'a.country_id',
                        'a.agent_id',
                        'a.image',
                        'a.image_pc',
                        'a.url',
                        'a.game_id',
                        'a.game_type_id',
                        'a.vendor_id',
                        DB::raw('DATE_ADD(a.start_time,INTERVAL 8 HOUR) as start_time'),
                        DB::raw('DATE_ADD(a.end_time,INTERVAL 8 HOUR) as end_time'),
                        'a.sort'
                    )
                    ->get();
                $data[$_key]['images'] = $images;
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (QueryException $e) {
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
     * advertise/view
     * -廣告明細
     *
     * @bodyParam id integer required 廣告編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *      "id": "系統編號,ex:1",
     *      "advertisement_path_id": "版位系統編號,ex:1",
     *      "agent_id": "營運商系統編號,ex:0",
     *      "type": "版面位置,ex:0",
     *      "open_window": "另開視窗,ex:0",
     *      "sort": "排序,ex:0",
     *      "status": "狀態,ex:0",
     *      "remark": "備註,ex:reamrk",
     *      "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *      "end_time": "結束時間,ex:2019-04-30 00:00:00",
     *      "detail": {}
     *   }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Advertise();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }


            $data = $model->where('id', $request->id)
                ->select(
                    'id',
                    'advertisement_path_id',
                    'agent_id',
                    'type',
                    'content',
                    'open_window',
                    'sort',
                    'status',
                    'remark',
                    DB::raw('DATE_ADD(start_time,INTERVAL 8 HOUR) as start_time'),
                    DB::raw('DATE_ADD(end_time,INTERVAL 8 HOUR) as end_time')
                )
                ->first();
            if (!$data) {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
                ]);
            }

            $data->detail = DB::table('advertise_languages as al')
                ->leftJoin('advertise_images as ai', 'ai.id', 'al.advertise_image_id')
                ->leftJoin('advertise_images as aii', 'aii.id', 'al.advertise_image_pc_id')
                ->where('al.advertise_id', $request->id)
                ->select(
                    'al.id',
                    'al.language_id',
                    'al.advertise_image_id',
                    'al.advertise_image_pc_id',
                    'al.admin',
                    'ai.image_url as advertise_image',
                    'aii.image_url as advertise_image_pc'
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (QueryException $e) {
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
     * advertisement/add
     * -新增廣告
     * @bodyParam path_id integer required 位置系統編號 Example: 1
     * @bodyParam language_id array required 語系系統編號 Example: [1,2]
     * @bodyParam agent_id integer 營運商系統編號 Example:1
     * @bodyParam type integer 類型 Example:1
     * @bodyParam image array base64 file 廣告圖片
     * @bodyParam image_pc array base64 file PC版廣告圖片
     * @bodyParam image_id array integer 廣告圖片ID
     * @bodyParam image_pc_id array integer PC版廣告圖片ID
     * @bodyParam contents string  點下圖片後的連結位置/遊戲系統編號/遊戲類型系統編號/遊戲商系統編號
     * @bodyParam sort integer 排序
     * @bodyParam status integer 排序
     * @bodyParam remark string 備註
     * @bodyParam start_time dateTime required 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime required 結束日期 Example: 2019-03-31 23:59:59
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        $begin_transaction = false;
        try {
            $model = new Advertise();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'));

            $result_id = null;

            DB::beginTransaction();
            $begin_transaction = true;

            $data = array();
            $data['advertisement_path_id'] = $request->path_id;
            $data['agent_id'] = $request->agent_id;
            $data['start_time'] = $start_time;
            $data['end_time'] = $end_time;
            $data['type'] = $request->type;
            $data['content'] = isset($request->contents) ? $request->contents : '';
            $data['open_window'] = isset($request->open_window) ? $request->open_window : 0;
            $data['sort'] = isset($request->sort) ? $request->sort : 0;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['remark'] = isset($request->remark) ? $request->remark : '';
            $data['admin'] = $request->ID;
            $advertise = Advertise::create($data);
            $create_result = true;
            foreach ($request->language_id as $language_id) {
                $data = array();
                $data['advertise_id'] = $advertise->id;
                $data['language_id'] = $language_id;
                $data['admin'] = $request->HOST;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image[$language_id]) && $request->image[$language_id] != '') {
                    if ($request->image_id[$language_id] > 0) {
                        $data['advertise_image_id'] = $request->image_id[$language_id];
                    } else {
                        $path = $this->uploadBase64FileToAdvertise($request->image[$language_id], true, $request->HOST);
                        if ($path['code'] != 1000) {
                            DB::rollback();
                            return response()->json([
                                'code' => $path['code'],
                                'message' => $path['message']
                            ]);
                        }
                        $data['advertise_image_id'] = $path['image_id'];
                    }
                }

                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image_pc[$language_id]) && $request->image_pc[$language_id] != '') {
                    if ($request->image_pc_id[$language_id] > 0) {
                        $data['advertise_image_pc_id'] = $request->image_pc_id[$language_id];
                    } else {
                        $path = $this->uploadBase64FileToAdvertise($request->image_pc[$language_id], true, $request->HOST);
                        if ($path['code'] != 1000) {
                            DB::rollback();
                            return response()->json([
                                'code' => $path['code'],
                                'message' => $path['message']
                            ]);
                        }
                        $data['advertise_image_pc_id'] = $path['image_id'];
                    }
                }
                $detail = AdvertiseLanguage::create($data);

                if ($detail) {
                    // 刪除Redis中相關的資料
                    //$redis = new REDIS();
                    //$redis->//delete(array(
                    //    'advertisement'
                    //));
                    //$result_id = $result->id;
                } else {
                    $create_result = false;
                }
            }
            if ($create_result) {
                DB::commit();

                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'advertisement',
                    'list'
                ));

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result_id
                ]);
            } else {
                DB::rollback();
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            if ($begin_transaction) DB::rollback();
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            if ($begin_transaction) DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * advertisement/edit
     * -編輯廣告
     *
     * @bodyParam id integer required 位置系統編號 Example: 1
     * @bodyParam path_id integer required 位置系統編號 Example: 1
     * @bodyParam language_id array required 語系系統編號 Example: [1,2]
     * @bodyParam agent_id integer 營運商系統編號 Example:1
     * @bodyParam type integer 類型 Example:1
     * @bodyParam image array base64 file 廣告圖片
     * @bodyParam image_pc array base64 file PC版廣告圖片
     * @bodyParam image_id array integer 廣告圖片ID
     * @bodyParam image_pc_id array integer PC版廣告圖片ID
     * @bodyParam contents string  點下圖片後的連結位置/遊戲系統編號/遊戲類型系統編號/遊戲商系統編號
     * @bodyParam sort integer 排序
     * @bodyParam status integer 排序
     * @bodyParam remark string 備註
     * @bodyParam start_time dateTime required 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime required 結束日期 Example: 2019-03-31 23:59:59
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        $begin_transaction = false;
        try {
            $model = new Advertise();
            if ($advertise = $model->find($request->id)) {
                $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
                if ($validator->passes()) {
                    $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'));
                    $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'));

                    $result_id = null;

                    DB::beginTransaction();
                    $begin_transaction = true;

                    $data = array();
                    $data['advertisement_path_id'] = $request->path_id;
                    $data['agent_id'] = $request->agent_id;
                    $data['start_time'] = $start_time;
                    $data['end_time'] = $end_time;
                    $data['type'] = $request->type;
                    $data['content'] = isset($request->contents) ? $request->contents : '';
                    $data['open_window'] = isset($request->open_window) ? $request->open_window : 0;
                    $data['sort'] = isset($request->sort) ? $request->sort : 0;
                    $data['status'] = isset($request->status) ? $request->status : 0;
                    $data['remark'] = isset($request->remark) ? $request->remark : '';
                    $data['admin'] = $request->ID;
                    if ($advertise->update($data)) {
                        $create_result = true;
                        $language = array();
                        foreach ($request->language_id as $language_id) {
                            $language[] = $language_id;
                            $data = array();
                            $data['advertise_id'] = $advertise->id;
                            $data['language_id'] = $language_id;
                            $data['admin'] = $request->HOST;
                            // 移動圖檔至本地及上傳到GCP
                            if (isset($request->image[$language_id]) && $request->image[$language_id] != '') {
                                if ($request->image_id[$language_id] > 0) {
                                    $data['advertise_image_id'] = $request->image_id[$language_id];
                                } else {
                                    $path = $this->uploadBase64FileToAdvertise($request->image[$language_id], true, $request->HOST);
                                    if ($path['code'] != 1000) {
                                        DB::rollback();
                                        return response()->json([
                                            'code' => $path['code'],
                                            'message' => $path['message']
                                        ]);
                                    }
                                    $data['advertise_image_id'] = $path['image_id'];
                                }
                            }

                            // 移動圖檔至本地及上傳到GCP
                            if (isset($request->image_pc[$language_id]) && $request->image_pc[$language_id] != '') {
                                if ($request->image_pc_id[$language_id] > 0) {
                                    $data['advertise_image_pc_id'] = $request->image_pc_id[$language_id];
                                } else {
                                    $path = $this->uploadBase64FileToAdvertise($request->image_pc[$language_id], true, $request->HOST);
                                    if ($path['code'] != 1000) {
                                        DB::rollback();
                                        return response()->json([
                                            'code' => $path['code'],
                                            'message' => $path['message']
                                        ]);
                                    }
                                    $data['advertise_image_pc_id'] = $path['image_id'];
                                }
                            }
                            $filter = array();
                            $filter['advertise_id'] = $advertise->id;
                            $filter['language_id'] = $language_id;
                            if (AdvertiseLanguage::updateOrCreate($filter, $data)) {
                            } else {
                                $create_result = false;
                            }
                        }
                        //刪除取消的語言
                        AdvertiseLanguage::where('advertise_id', $request->id)
                            ->whereNotIn('language_id', $language)
                            ->delete();

                        if ($create_result) {
                            DB::commit();

                            // 刪除Redis中相關的資料
                            $redis = new REDIS();
                            $redis->delete(array(
                                'advertisement',
                                'list'
                            ));

                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => $result_id
                            ]);
                        } else {
                            DB::rollback();
                            return response()->json([
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ]);
                        }
                    } else {
                        DB::rollback();
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                } else {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                        'data' => $validator->errors()
                            ->all()
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
                ]);
            }
        } catch (QueryException $e) {
            if ($begin_transaction) DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            if ($begin_transaction) DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1126'),
            'id.digits_between' => __('error_message.1127'),

            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104'),

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'sort.digits_between' => __('error_message.1125'),

            'start_time.required' => __('error_message.1105'),
            'start_time.date_format' => __('error_message.1106'),

            'end_time.required' => __('error_message.1107'),
            'end_time.date_format' => __('error_message.1108'),
            'end_time.after' => __('error_message.1109'),

            'status.boolean' => __('error_message.1104')
        ];
    }
}