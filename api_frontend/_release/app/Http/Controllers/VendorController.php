<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\SQLHelper;
use App\Models\VsportAccount;
use App\VendorVsport;
use App\Helpers\Common;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Services\VendorService;
use App\Models\AgentGroup;
use App\Models\MemberAccessToken;
use App\Models\MemberLoginLog;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorAccountNamedLock;
use App\Models\VendorRequestFail;
use App\Models\Member;
use App\Models\Agent;
use App\Models\Game;
use App\Models\GameType;
use App\Models\Language;
use App\Models\TransactionLog;
use App\Models\AgentVendor;
use App\Models\TransferLimit;
use App\Models\AgAccount;
use App\Models\JokerAccount;
use App\Models\ScrAccount;
use App\Models\HaolongAccount;
use App\Models\GreatWallAccount;
use App\Models\Ace333Account;
use App\Models\VplayAccount;
use App\Models\CokeFightingAccount;
use App\Models\Mega888Account;
use App\Models\Xe88Account;
use App\Models\LuckyAccount;
use App\Models\EbetAccount;
use App\Models\IsbAccount;
use App\Models\YggAccount;
use App\Models\WmAccount;
use App\Models\PtAccount;
use App\Models\SboAccount;
use App\Models\MtAccount;
use App\Models\PgAccount;
use App\Models\IaAccount;
use App\Models\VpowerAccount;
use App\Models\Pussy888Account;
use App\Models\PgsAccount;
use App\Models\AesAccount;
use App\Models\IgsAccount;
use App\Models\CtAccount;
use App\Models\Sky1388Account;
use App\Models\DgAccount;
use App\Models\Play8oyAccount;
use App\Models\RmAccount;
use App\Models\TerraAccount;
use App\Models\AllbetAccount;
use App\Models\TestgameAccount;
use App\Models\SkybetAccount;
use App\Models\N918Account;
use App\Models\VspokerAccount;
use App\Models\SaAccount;
use App\Models\EsportAccount;
use App\Models\Menang88Account;
use App\Models\NptAccount;
use App\Models\FishingAccount;
use App\Models\V8Account;
use App\Models\Obet33Account;
use App\Models\SabaAccount;
use App\Models\Bola2Account;
use App\Models\Plus100Account;
use App\Models\SpadegamingAccount;
use App\Models\NsAccount;
use App\Models\BngAccount;
use App\Models\PgsswAccount;
use App\Models\MgcAccount;
use App\Models\LoginEvent;
use App\Models\YbetAccount;
use App\VendorAg;
use App\VendorJoker;
use App\VendorScr;
use App\VendorHaolong;
use App\VendorGreatWall;
use App\VendorAce333;
use App\VendorVplay;
use App\VendorCokeFighting;
use App\VendorMega888;
use App\VendorXe88;
use App\VendorLucky;
use App\VendorEbet;
use App\VendorIsb;
use App\VendorYgg;
use App\VendorWm;
use App\VendorPt;
use App\VendorSbo;
use App\VendorMt;
use App\VendorPg;
use App\VendorIa;
use App\VendorVpower;
use App\VendorPussy888;
use App\VendorPgs;
use App\VendorAes;
use App\VendorIgs;
use App\VendorCt;
use App\VendorSky1388;
use App\VendorDg;
use App\VendorPlay8oy;
use App\VendorSkybet;
use App\VendorRm;
use App\VendorTerra;
use App\VendorN918;
use App\VendorAllbet;
use App\VendorVSPoker;
use App\VendorSa;
use App\VendorFishing;
use App\VendorMenang88;
use App\VendorTestgame;
use App\VendorEsport;
use App\VendorNpt;
use App\VendorV8;
use App\VendorObet33;
use App\VendorSaba;
use App\VendorBola2;
use App\VendorPlus100;
use App\VendorSpadeGaming;
use App\VendorNs;
use App\VendorBng;
use App\VendorPgssw;
use App\VendorMgc;
use App\VendorYbet;
use App\Services\TelegramService;
use App\REDIS;
use DateTime;
use Log;
use SebastianBergmann\CodeCoverage\Report\PHP;
use Validator;
use DB;

/**
 * @group Vendor
 */
class VendorController extends Controller
{
    protected $tgService;
    public function __construct()
    {
        $this->tgService = new TelegramService();
    }

    public function testLockWait(Request $request)
    {
        //簡單的方案是取遊戲帳號前先進行 lock
        $lock = new VendorAccountNamedLock(1, 1);
        $vendor = new AgAccount();
        $account = $vendor->useWritePdo()
            ->where('member_id', 1)
            ->orderBy('id', 'ASC')
            ->first();
        var_dump($account->id);
        sleep(10);
        $lock->unLock();
        $now = DateTime::createFromFormat('U.u', microtime(true));
        return response()->json([
            'message' => $now->format('m-d-Y H:i:s.u')
        ]);
    }

    public function testLock(Request $request)
    {
        $lock = new VendorAccountNamedLock(1, 1);
        $vendor = new AgAccount();
        $account = $vendor->useWritePdo()
            ->where('member_id', 1)
            ->orderBy('id', 'ASC')
            ->first();
        var_dump($account->id);
        $lock->unLock();
        $now = DateTime::createFromFormat('U.u', microtime(true));
        return response()->json([
            'message' => $now->format('m-d-Y H:i:s.u')
        ]);
    }

    /**
     * vendors
     * - 遊戲商列表
     *
     * @bodyParam status integer 狀態1:開啟, 2:coming soon, 3:維護中
     * @bodyParam type integer 類型(預設1)1:遊戲列表, 2:轉點列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "app桌面圖片,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "is_new": "是否顯示新遊戲商標籤0:不顯示1:顯示,ex:0",
     *            "is_hot": "是否顯示熱門遊戲商標籤0:不顯示1:顯示,ex:0",
     *            "need_login": "是否需要另外登入0:不需1:需要,ex:0",
     *            "only_one_game": "是否只有一款遊戲0:不是1:是,ex:0",
     *            "is_slow": "是否回應較慢0:不是1:是,ex:0",
     *            "status": "狀態1:開啟，2:coming soon，3:維護中，4:禁止遊玩,ex:3",
     *            "maintenance_end_time": "維護結束時間,ex:2019-12-10 00:00:00"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg",
     *            "is_new": "1",
     *            "is_hot": "0",
     *            "need_login": 1,
     *            "only_one_game": 0,
     *            "is_slow": 0,
     *            "status": 2,
     *            "maintenance_end_time": ""
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $rule = [
                'status' => 'in:1,2,3'
            ];
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //判斷用戶使用的裝置
            if (isset($request->is_app) && $request->is_app == 1) {
                $device = 0;
            } elseif ($request->IS_MOBILE) {
                $device = 1;
            } else {
                $device = 2;
            }
            $vendors = $this->vendors(
                $request->ID,
                $request->status,
                $request->type,
                false,
                $request->IS_MOBILE,
                $device
            );
            return response()->json($vendors);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * vendors
     * - 遊戲商列表
     *
     * @bodyParam status interger 狀態1:開啟，2:coming soon，3:維護中
     *
     *
     */
    public function newList(Request $request)
    {
        try {
            $rule = [
                'status' => 'in:1,2,3'
            ];
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //判斷用戶使用的裝置
            if (isset($request->is_app) && $request->is_app == 1) {
                $device = 0;
            } elseif ($request->IS_MOBILE) {
                $device = 1;
            } else {
                $device = 2;
            }
            $vendors = $this->newVendors(
                $request->ID,
                $device
            );
            return response()->json($vendors);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

     /**
     * vendor/rate/list
     * -遊戲商列表(轉換比例資訊)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *    ]
     * }
     */
    public function rate_list(Request $request)
    {
        try {
            // 取自己的營運商
            $member = Member::find($request->ID);
            $agent = Agent::find($member->parent);

            $parents = explode(',', $agent->hierarchy);
            $vendors = array();
            if (count($parents) > 0) {
                foreach ($parents as $parent) {
                    $vendors = DB::table('vendors as v')
                        ->join('vendor_infos as vi', function ($join) {
                            $join->on('vi.vendor_id', 'v.id')
                                ->where('vi.status', 1);
                        })
                        ->join('agent_vendors as av', function ($join) use ($parent) {
                            $join->on('av.vendor_info_id', 'vi.id')
                                ->where('av.status', 1)
                                ->where('av.agent_id', $parent);
                        })
                        ->leftJoin('vendor_images as vim', function ($join) use ($agent) {
                            $join->on('vim.vendor_id', 'v.id')
                                ->where('agent_group_id', $agent->vendor_customize_group_id);
                        })
                        ->where('v.status', 1)
                        ->orderBy('v.id', 'ASC')
                        ->select(
                            'v.id',
                            DB::raw('(CASE WHEN vim.name IS NULL THEN v.name ELSE vim.name END) as name'),
                            'v.valid_bet_rate as unlimit_rate'
                        )
                        ->get();
                    if (count($vendors) > 0) {
                        break;
                    }
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $vendors
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * vendors
     * - 遊戲商支援的遊戲類別列表
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *    "queryEachVendorGameGroup": [
     *        {
     *            "vendor_id": 1,
     *            "type_id": 1,
     *            "root_type": 1
     *        },
     *        {
     *           "vendor_id": 1,
     *           "type_id": 2,
     *           "root_type": 2
     *        }
     *    ]
     * }
     */
    public function gameTypes(Request $request)
    {
        try {
            $queryGameTypes = GameType::select('id', 'parent')->get();
            $queryEachVendorGameGroup = Game::groupBy('vendor_id')
                ->where('status', 1)
                ->groupBy('type_id')
                ->select(
                    'vendor_id',
                    'type_id',
                    DB::raw('0 as root_type')
                )
                ->get();

            foreach ($queryEachVendorGameGroup as $item) {
                $matchItem = $this->getArrayItemByColumnValue($queryGameTypes, 'id', $item->type_id);
                if (!is_null($matchItem)) {
                    //查出該遊戲root類別 並設置array每筆資料的主分類
                    $item->root_type = $matchItem->parent == 0 ? $matchItem->id : $matchItem->parent;
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'queryEachVendorGameGroup' => $queryEachVendorGameGroup
                ]
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * @param memberId required integer 會員ID;
     * @param status integer 狀態1:開啟, 2:coming soon, 3:維護中
     * @param device integer 裝置: 0 APP, 1 手機網頁, 2 電腦網頁, null 不判斷裝置
     * @return array|\Illuminate\Http\JsonResponse
     * testcase
     *      1 vendorList 抛出錯誤
     *      2 query exception
     *      3 common exception
     *
     */
    public function newVendors($memberId, $device = null) {
        try {
            // type 固定為 1
            $type = 1;
            //查詢這個會員可以玩的遊戲商
            $vendors = $this->vendorList($memberId);
            // 未指定 vendor_id 不要排除
            $vendors[] = 0;
            //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
            if (!isset($vendors['code'])) {
                //查詢玩家目前狀態是否在鎖分中 "status": "狀態 1:開啟，2:coming soon，3:維護中，4:禁止遊玩,ex:3"
                $event_mode = $this->checkMemberEventLockByMemberId($memberId);
                if ($event_mode > 0) {
                    $use_vendor = LoginEvent::where('id', $event_mode)->pluck('vendor_id')->first();
                    //如果玩家進入平台鎖分模式，APP相關及不可參加鎖分活動的遊戲商狀態為4
                    if ($type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw("(CASE WHEN ((vm.id and (vm.type = 1 or vm.type = 3)) || vrf.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE v.status END) as status");
                    } else {
                        //遊戲頁面用
                        $status = DB::raw("(CASE WHEN ((vm.id and (vm.type = 1 or vm.type = 2)) || vrf.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE vci.status END) as status");
                    }
                } else {
                    if ($type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw('IF(((vm.id and (vm.type=1 or vm.type=3)) || vrf.id is not null),3, v.status) as status');
                    } else {
                        //遊戲頁面用
                        $status = DB::raw('IF((vm.id and (vm.type=1 or vm.type=2)),3, vci.status) as status');
                    }
                }
                $agents = $this->getAgentHierarchyPath($memberId);

                $agent_game_lists = $this->agentGameList($memberId, $vendors);
                // 取出首頁客製入口
                $model = DB::table('vendor_customized_items as vci')
                    ->join('agents as a', 'a.vendor_customize_group_id', 'vci.agent_group_id')
                    ->join('members as m', 'm.parent', 'a.id')
                    ->leftJoin('vendors as v', 'v.id', 'vci.vendor_id')
                    ->leftJoin('vendor_maintenances as vm', function ($join) use ($agents) {
                        $join->on('vm.vendor_id', 'v.id')
                            ->where('vm.status', 1)
                            ->whereRaw('vm.start_time <= NOW() and vm.end_time >= NOW()')
                            ->whereIn('vm.agent_id', $agents)
                            ->whereNotIn('vm.exclude_agent_id', $agents);
                    })
                    ->leftJoin('vendor_request_fails as vrf', function ($join) {
                        $join->on('vrf.vendor_id', 'v.id')
                            ->where('vrf.status', '<', 2);
                    })
                    ->where('m.id', $memberId)
                    ->where('vci.status', 1)
                    ->whereIn('vci.vendor_id', $vendors)
                    ->where(function ($query) use ($agent_game_lists) {
                        $query->where('vci.game_id', 0)
                            ->orWhereIn('vci.game_id', $agent_game_lists);
                    })
                    ->select(
                        'vci.item_type',
                        'vci.vendor_id as id',
                        'vci.game_id',
                        'vci.type_id',
                        'vci.path',
                        DB::raw('IFNULL(vci.name, v.name) as name'),
                        DB::raw('IFNULL(vci.image, v.image) as image'),
                        DB::raw('IFNULL(vci.app_icon, v.app_icon) as app_icon'),
                        DB::raw('IFNULL(vci.is_new, v.is_new) as is_new'),
                        DB::raw('IFNULL(vci.is_hot, v.is_hot) as is_hot'),
                        'v.only_one_game',
                        'v.need_login',
                        $status,
                        DB::raw('DATE_ADD(MAX(vm.end_time),INTERVAL 8 HOUR) as maintenance_end_time')
                    )
                    ->groupBy('vci.item_type', 'vci.vendor_id', 'vci.game_id', 'vci.type_id', 'vci.path')
                    ->orderByDesc('vci.sorts');
                //判斷訪問裝置是否顯示
                if ($device == 0) {
                    //APP版顯示的遊戲商
                    $model->where(function($query) {
                        $query->where('v.show_on_app', 1)
                            ->orWhere('vci.vendor_id', 0);
                    });
                } elseif ($device == 1) {
                    //手機版顯示的遊戲商
                    $model->where(function($query) {
                        $query->where('v.show_on_mobile', 1)
                            ->orWhere('vci.vendor_id', 0);
                    });
                } elseif ($device == 2) {
                    //PC版顯示的遊戲商
                    $model->where(function($query)
                    {
                        $query->where('v.show_on_pc', 1)
                            ->orWhere('vci.vendor_id', 0);
                    });
                }
                Log::info("vendors list [" . Common::debugSQL($model) . "]", $vendors);
                $data = $model->get();

                //查到有設定的帳號為止
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ];
            } else {
                //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
                return $vendors;
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * @param $memberId
     * @param null $status 狀態1:開啟，2:coming soon，3:維護中
     * @param null $type 類型(預設1)1:遊戲列表，2:轉點列表
     * @param bool $eventLock
     * @param null $isMobile
     * @param int $device 裝置: 0 APP, 1 手機網頁, 2 電腦網頁
     * @return array|\Illuminate\Http\JsonResponse
     */
    public function vendors($memberId, $status = null, $type = null, $eventLock = false, $isMobile = null, $device = 3)
    {
        try {
            //查詢這個會員可以玩的遊戲商
            $vendors = $this->vendorList($memberId);
            //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
            if (!isset($vendors['code'])) {
                $vendorImage = DB::table('vendor_images as vi')
                    ->join('agent_groups as ag', 'ag.id', 'vi.agent_group_id')
                    ->join('agents as a', 'a.vendor_customize_group_id', 'ag.id')
                    ->join('members', function ($join) use ($memberId) {
                        $join->on('members.parent', 'a.id')
                            ->where('members.id', $memberId);
                    })
                    ->select('vi.*');
                $bindings = $vendorImage->getBindings();
                $vendorImageSql = str_replace('?', '%s', $vendorImage->toSql());
                $vendorImageSql = sprintf($vendorImageSql, ...$bindings);

                $agents = $this->getAgentHierarchyPath($memberId);

                $data = DB::table('vendors as v')
                    ->leftJoin('vendor_maintenances as m', function ($join) use ($agents) {
                        $join->on('v.id', 'm.vendor_id')
                            ->where('m.status', 1)
                            ->where('m.start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('m.end_time', '>=', date('Y-m-d H:i:s'))
                            ->whereIn('m.agent_id', $agents)
                            ->whereNotIn('m.exclude_agent_id', $agents);
                    })
                    ->leftJoin('vendor_request_fails as f', function ($join) {
                        $join->on('v.id', 'f.vendor_id')
                            ->where('f.status', '<', 2);
                    })
                    ->leftJoin(DB::raw("(".$vendorImageSql.") AS b" ), "b.vendor_id", "v.id");

                // 如果有指定狀態時就依指定的查詢，不然就顯示所有(除了禁用的)
                if (isset($status) && $status != '') {
                    $data = $data->where('v.status', $status);
                } else {
                    $data = $data->where('v.status', '>', 0);
                }

                //查詢可參與鎖分活動的遊戲商(鎖分活動的不區分玩家設備，一律檢查)
                if ($eventLock > 0) {
                    //找出該登入活動
                    $login_event = LoginEvent::find($eventLock);
                    $event_vendors = explode(',', $login_event->vendor_id);
                    $data = $data->whereIn('v.id', $event_vendors);
                } else {
                    // 依玩家設備判斷是否顯示
                    if($device == 0) {
                        //APP版顯示的遊戲商
                        $data = $data->where('v.show_on_app', 1);
                    } elseif ($device == 1) {
                        //手機版顯示的遊戲商
                        $data = $data->where('v.show_on_mobile', 1);
                    } elseif ($device == 2) {
                        //PC版顯示的遊戲商
                        $data = $data->where('v.show_on_pc', 1);
                    }
                }

                //查詢玩家目前狀態是否在鎖分中
                $event_mode = $this->checkMemberEventLockByMemberId($memberId);
                if ($event_mode > 0) {
                    $use_vendor = LoginEvent::where('id', $event_mode)->pluck('vendor_id')->first();
                    //如果玩家進入平台鎖分模式，APP相關及不可參加鎖分活動的遊戲商狀態為4
                    if ($type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw("(CASE WHEN ((m.id and (m.type=1 or m.type=3)) || f.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE v.status END) as status");
                    } else {
                        //遊戲頁面用
                        $status = DB::raw("(CASE WHEN ((m.id and (m.type=1 or m.type=2)) || f.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE v.status END) as status");
                    }
                } else {
                    if ($type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw('IF(((m.id and (m.type=1 or m.type=3)) || f.id is not null),3, v.status) as status');
                    } else {
                        //遊戲頁面用
                        $status = DB::raw('IF((m.id and (m.type=1 or m.type=2)),3, v.status) as status');
                    }
                }

                $data = $data->whereIn('v.id', $vendors)
                    ->groupBy('v.id')
                    ->orderBy('v.sort', 'DESC')
                    ->select(
                        'v.id',
                        DB::raw('(CASE WHEN b.name IS NULL THEN v.name ELSE b.name END) as name'),
                        'v.rate',
                        DB::raw('(CASE WHEN b.image IS NULL THEN v.image ELSE b.image END) as image'),
                        DB::raw('(CASE WHEN b.image IS NULL THEN v.app_icon ELSE b.app_icon END) as app_icon'),
                        DB::raw('0 AS is_new'),
                        DB::raw('0 AS is_hot'),
                        'v.need_login',
                        'v.only_one_game',
                        'v.is_slow',
                        $status,
                        DB::raw('DATE_ADD(MAX(m.end_time),INTERVAL 8 HOUR) as maintenance_end_time'),
                        'v.is_single_wallet'
                    );
                Log::info("vendors list [" . $data->toSql() . "]", $vendors);
                $data = $data->get();

                //取得customer setting
                $settings = DB::table('members as m')
                    ->join('agents as a', function ($join) {
                        $join->on('a.id', 'm.parent');
                    })
                    ->join('agent_customized_settings as s', function ($join) {
                        $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                            ->whereIn(
                                's.setting_key',
                                [
                                    AgentCustomizedSettingKeyEnum::VENDOR_SORT,
                                    AgentCustomizedSettingKeyEnum::HOT_VENDOR_FLAG,
                                    AgentCustomizedSettingKeyEnum::NEW_VENDOR_FLAG
                                ]
                            );
                    })
                    ->where('m.id', $memberId)
                    ->select(
                        's.setting_key',
                        DB::RAW("IFNULL(s.value, '') as value")
                    )
                    ->get();

                $new_data = array();
                if (is_object($settings) && count($settings) > 0) {
                    $ar_sort = [];
                    $ar_hot = [];
                    $ar_new = [];
                    foreach($settings as $row) {
                        if ($row->setting_key == AgentCustomizedSettingKeyEnum::VENDOR_SORT) {
                            $ar_sort = explode(',', $row->value);
                        } elseif ($row->setting_key == AgentCustomizedSettingKeyEnum::HOT_VENDOR_FLAG) {
                            $ar_hot = explode(',', $row->value);
                        } elseif ($row->setting_key == AgentCustomizedSettingKeyEnum::NEW_VENDOR_FLAG) {
                            $ar_new = explode(',', $row->value);
                        }
                    }

                    if (count($ar_hot) > 0 || count($ar_new) > 0) {
                        foreach ($data as $key => $obj) {
                            if (in_array($obj->id, $ar_hot)) {
                                $obj->is_hot = 1;
                            }

                            if (in_array($obj->id, $ar_new)) {
                                $obj->is_new = 1;
                            }
                        }
                    }

                    if (count($ar_sort) > 0) {
                        foreach ($ar_sort as $vendor_id) {
                            foreach ($data as $key => $obj) {
                                if ($obj->id == $vendor_id) {
                                    $new_data[] = $obj;
                                    unset($data[$key]);
                                }
                            }
                        }
                    }
                }
                foreach ($data as $key => $obj) {
                    $new_data[] = $obj;
                }
                $data = $new_data;

                //查到有設定的帳號為止
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ];
            } else {
                //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
                return $vendors;
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * vendor
     * - 遊戲商明細
     *
     * @bodyParam vendor_id interger 遊戲商系統編號
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "app桌面圖片,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "is_new": "是否顯示新遊戲商標籤0:不顯示1:顯示,ex:0",
     *            "is_hot": "是否顯示熱門遊戲商標籤0:不顯示1:顯示,ex:0",
     *            "need_login": "是否需要另外登入0:不需1:需要,ex:0",
     *            "only_one_game": "是否只有一款遊戲0:不是1:是,ex:0",
     *            "is_slow": "是否回應較慢0:不是1:是,ex:0",
     *            "status": "狀態1:開啟，2:coming soon，3:維護中，4:禁止遊玩,ex:3",
     *            "maintenance_end_time": "維護結束時間,ex:2019-12-10 00:00:00"
     *        }
     * }
     */
    public function show(Request $request)
    {
        try {
            $rule = [
                'vendor_id' => 'required|digits_between:0,10'
            ];
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //查詢這個會員可以玩的遊戲商
            $vendors = $this->vendorList($request->ID);
            //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
            if (!isset($vendors['code'])) {
                $vendorImage = DB::table('vendor_images as vi')
                    ->join('agent_groups as ag', 'ag.id', 'vi.agent_group_id')
                    ->join('agents as a', 'a.vendor_customize_group_id', 'ag.id')
                    ->join('members', function ($join) use ($request) {
                        $join->on('members.parent', 'a.id')
                            ->where('members.id', $request->ID);
                    })
                    ->select('vi.*');
                $bindings = $vendorImage->getBindings();
                $vendorImageSql = str_replace('?', '%s', $vendorImage->toSql());
                $vendorImageSql = sprintf($vendorImageSql, ...$bindings);

                $agents = $this->getAgentHierarchyPath($request->ID);

                $data = DB::table('vendors as v')
                    ->leftJoin('vendor_maintenances as m', function ($join) use ($agents) {
                        $join->on('v.id', 'm.vendor_id')
                            ->where('m.status', 1)
                            ->where('m.start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('m.end_time', '>=', date('Y-m-d H:i:s'))
                            ->whereIn('m.agent_id', $agents)
                            ->whereNotIn('m.exclude_agent_id', $agents);
                    })
                    ->leftJoin('vendor_request_fails as f', function ($join) {
                        $join->on('v.id', 'f.vendor_id')
                            ->where('f.status', '<', 2);
                    })
                    ->leftJoin(DB::raw("(".$vendorImageSql.") AS b" ), "b.vendor_id", "v.id");

                $data = $data->where('v.status', '>', 0);
                //查詢玩家目前狀態是否在鎖分中
                $event_mode = $this->checkMemberEventLockByMemberId($request->ID);
                if ($event_mode > 0) {
                    $use_vendor = LoginEvent::where('id', $event_mode)->pluck('vendor_id')->first();
                    //如果玩家進入平台鎖分模式，APP相關及不可參加鎖分活動的遊戲商狀態為4
                    if ($request->type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw("(CASE WHEN ((m.id and (m.type=1 or m.type = 3)) || f.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE v.status END) as status");
                    } else {
                        //遊戲頁面用
                        $status = DB::raw("(CASE WHEN ((m.id and (m.type = 1 or m.type = 2)) || f.id is not null) THEN 3 WHEN v.id NOT IN (" . $use_vendor . ") THEN 4 ELSE v.status END) as status");
                    }
                } else {
                    if ($request->type == 2) {
                        //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                        $status = DB::raw('IF(((m.id and (m.type=1 or m.type=3)) || f.id is not null),3, v.status) as status');
                    } else {
                        //遊戲頁面用
                        $status = DB::raw('IF((m.id and (m.type=1 or m.type=2)),3, v.status) as status');
                    }
                }
                $data = $data->where('v.id', $request->vendor_id)
                    ->groupBy('v.id')
                    ->select(
                        'v.id',
                        DB::raw('1 as item_type'),
                        DB::raw('0 as type_id'),
                        DB::raw('0 as game_id'),
                        DB::raw('"" as path'),
                        DB::raw('(CASE WHEN b.name IS NULL THEN v.name ELSE b.name END) as name'),
                        'v.rate',
                        DB::raw('(CASE WHEN b.image IS NULL THEN v.image ELSE b.image END) as image'),
                        DB::raw('(CASE WHEN b.image IS NULL THEN v.app_icon ELSE b.app_icon END) as app_icon'),
                        'v.is_new',
                        'v.is_hot',
                        'v.need_login',
                        'v.only_one_game',
                        'v.is_slow',
                        $status,
                        DB::raw('DATE_ADD(MAX(m.end_time),INTERVAL 8 HOUR)  as maintenance_end_time')
                    )
                    ->first();

                if ($data->status == 3) {
                    return response()->json([
                        'code' => 1938,
                        'message' => __('error_message.1938')
                    ]);
                } else {
                    //查到有設定的帳號為止
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                }
            } else {
                return response()->json($vendors);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    public function newShow(Request $request)
    {
        try {
            $rule = [
                'vendor_id' => 'required|digits_between:0,10'
            ];
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // type 固定為 1
            $type = 1;
            $memberId = $request->ID;
            //查詢這個會員可以玩的遊戲商
            $vendors = $this->vendorList($request->ID);
            //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
            if (!isset($vendors['code'])) {

                if ($type == 2) {
                    //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                    $status = DB::raw('IF(((vm.id and (vm.type=1 or vm.type=3)) || vrf.id is not null),3, v.status) as status');
                } else {
                    //遊戲頁面用
                    $status = DB::raw('IF((vm.id and (vm.type=1 or vm.type=2)),3, vci.status) as status');
                }
                $agents = $this->getAgentHierarchyPath($memberId);
                // 取出首頁客製入口
                $model = DB::table('vendor_customized_items as vci')
                    ->join('agents as a', 'a.vendor_customize_group_id', 'vci.agent_group_id')
                    ->join('members as m', 'm.parent', 'a.id')
                    ->leftJoin('vendors as v', 'v.id', 'vci.vendor_id')
                    ->leftJoin('vendor_maintenances as vm', function ($join) use ($agents) {
                        $join->on('vm.vendor_id', 'v.id')
                            ->where('vm.status', 1)
                            ->whereRaw('vm.start_time <= NOW() and vm.end_time >= NOW()')
                            ->whereIn('vm.agent_id', $agents)
                            ->whereNotIn('vm.exclude_agent_id', $agents);
                    }, )
                    ->leftJoin('vendor_request_fails as vrf', function ($join) {
                        $join->on('vrf.vendor_id', 'v.id')
                            ->where('vrf.status', '<', 2);
                    })
                    ->where('m.id', $memberId)
                    ->where('vci.status', 1)
                    ->where('vci.item_type', 1)
                    ->whereIn('vci.vendor_id', $vendors)
                    ->where('vci.vendor_id', $request->vendor_id)
                    ->select(
                        'vci.item_type',
                        'vci.vendor_id as id',
                        'vci.game_id',
                        'vci.type_id',
                        'vci.path',
                        DB::raw('IFNULL(vci.name, v.name) as name'),
                        DB::raw('IFNULL(vci.image, v.image) as image'),
                        DB::raw('IFNULL(vci.app_icon, v.app_icon) as app_icon'),
                        DB::raw('IFNULL(vci.is_new, v.is_new) as is_new'),
                        DB::raw('IFNULL(vci.is_hot, v.is_hot) as is_hot'),
                        'v.need_login',
                        'v.only_one_game',
                        $status,
                        DB::raw('DATE_ADD(MAX(vm.end_time),INTERVAL 8 HOUR) as maintenance_end_time')
                    )
                    ->groupBy('vci.item_type', 'vci.vendor_id', 'vci.game_id', 'vci.type_id', 'vci.path')
                    ->orderByDesc('vci.sorts');
                Log::info("get vendor [" . Common::debugSQL($model) . "]", $vendors);
                $data = $model->first();

                if ($data->status == 3) {
                    return response()->json([
                        'code' => 1938,
                        'message' => __('error_message.1938')
                    ]);
                } else {
                    //查到有設定的帳號為止
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                }
            } else {
                return response()->json($vendors);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * getVendorBalance
     * - 遊戲商平台餘額
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "0.00"
     * }
     */
    public function getBalance(Request $request)
    {
        $rule = [
            'vendor_id' => 'required|digits_between:0,10'
        ];
        $validator = Validator::make($request->all(), $rule, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $result = $this->getBalanceByVendorId($request->ID, $request->vendor_id, 0, $request->LANGUAGE);
        if (is_array($result) && isset($result['data']) && is_numeric($result['data'])) {
            $result['data'] = number_format($result['data'], 4);
        }
        return response()->json($result);
    }

    /**
     * 取遊戲商錢包餘額
     *
     * @param $memberId int 玩家ID
     * @param $vendorId int 遊戲商ID
     * @param $show_disable int 遊戲商管理關閉時, 是否顯示
     *
     * @return object
    */
    public function getBalanceByVendorId($memberId, $vendorId, $show_disable = 0, $agent_group_id = 1)
    {
        $vendorInfo = Vendor::find($vendorId);
        if ($vendorInfo->is_single_wallet == 1) {
            //多錢包
            $member = DB::table('members as m')
                ->join('wallet_types as wt', function ($join) {
                    $join->on('wt.id', 'm.wallet_type_id')
                        ->where('wt.status', 1);
                })
                ->join('member_wallets as mw', function ($join) use ($memberId) {
                    $join->on('mw.wallet_type_id', 'm.wallet_type_id')
                        ->where('mw.member_id', $memberId)
                        ->where('mw.status', 1);
                })
                ->join('agent_wallets as aw', function ($join) use ($agent_group_id) {
                    $join->on('aw.wallet_type_id', 'm.wallet_type_id')
                        ->where('aw.agent_group_id', $agent_group_id)
                        ->where('aw.status', 1);
                })
                ->where('m.id', $memberId)
                ->select(
                    'm.wallet_type_id',
                    'mw.balance'
                )
                ->first();

            //如果遊戲商是單錢包,直接回傳平台商錢包
            $result = [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $member->balance
            ];
        } else {
            list($account, $info) = $this->getVendorAccount($memberId, $vendorId, $show_disable);
            if (!is_object($account)) {
                return $account;
            } else {
                //
                $old_account = $account->username;
                // 是否重新綁定帳號
                $rebind_account = false;
                switch ($vendorId) {
                    // AG
                    case 1:
                        $vendor = new VendorAg($info);
                        if (isset($account->password) && $account->password != '') {
                            $password = [REDACTED], 0, 20);
                        } else {
                            $password = [REDACTED], $account->created_at);
                        }
                        $result = $vendor->getBalance($account->username, $password);
                        //如果會員不存在就重新綁帳號
                        if (isset($result['message'][0]) && substr($result['message'][0], 0, 11) == 'error:60001') {
                            $rebind_account = true;
                            $vendor_account = new AgAccount();
                            $vendor_account->find($account->id)->update(['member_id' => -$account->member_id]);
                            if ($account = $vendor_account->where('member_id', 0)->first()) {
                                $vendor_account->find($account->id)->update(array(
                                    'member_id' => $memberId
                                ));
                                $result = $vendor->getBalance(
                                    $account->username,
                                    $this->getVendorPassword(
                                        $account->username,
                                        $account->created_at
                                    )
                                );
                            }
                        }
                        break;
                    // Joker
                    case 2:
                        $vendor = new VendorJoker($info);
                        $result = $vendor->getBalance($account->username);
                        //如果會員不存在就重新綁帳號
                        if (isset($result['message']['Message']) && strpos($result['message']['Message'], 'was not found') !== false) {
                            $rebind_account = true;
                            $vendor_account = new JokerAccount();
                            $vendor_account->find($account->id)
                                ->update(
                                    [
                                        'member_id' => -$account->member_id
                                    ]
                                );
                            if ($account = $vendor_account->where('member_id', 0)->first()) {
                                $vendor_account->find($account->id)
                                    ->update(
                                        [
                                            'member_id' => $memberId
                                        ]
                                    );
                                $result = $vendor->getBalance($account->username);
                            }
                        }
                        break;
                    // SCR(918)
                    case 3:
                        $vendor = new VendorScr($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    // 豪龍
                    case 4:
                        $vendor = new VendorHaolong($info);
                        $result = $vendor->getBalance($account->username);
                        //如果會員不存在就重新綁帳號
                        if (isset($result['message'][0]) && $result['message'][0] == 'Login ID Not Existed') {
                            $rebind_account = true;
                            $vendor_account = new HaolongAccount();
                            $vendor_account->find($account->id)
                                ->update(
                                    [
                                        'member_id' => -$account->member_id
                                    ]
                                );
                            $account = $vendor_account->where('member_id', 0)->where('vendor_info_id', $info->id)->first();
                            if ($account) {
                                $vendor_account->find($account->id)
                                    ->update(
                                        array('member_id' => $memberId)
                                    );
                                $result = $vendor->getBalance($account->username);
                            }
                        }
                        break;
                    // GW99
                    case 5:
                        $vendor = new VendorGreatWall($info);
                        $result = $vendor->getBalance($account->username);
                        //如果會員不存在就重新綁帳號
                        if (isset($result['message']['result']['code']) && $result['message']['result']['code'] == '-90208') {
                            $rebind_account = true;
                            $vendor_account = new GreatWallAccount();
                            $vendor_account->find($account->id)
                                ->update(
                                    [
                                        'member_id' => -$account->member_id
                                    ]
                                );
                            if ($account = $vendor_account->where('member_id', 0)->first()) {
                                $vendor_account->find($account->id)->update(array(
                                    'member_id' => $memberId
                                ));
                                $result = $vendor->getBalance($account->username);
                            }
                        }
                        break;
                    //ACE333
                    case 6:
                        $vendor = new VendorAce333($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    //沒有雞的鬥雞
                    // case 7:
                    //     $vendor = new VendorChichenGame();
                    //     $result = $vendor->getBalance($account->username);
                    //     break;
                    //vplay
                    case 8:
                        $vendor = new VendorVplay($info);
                        $result = $vendor->getBalance($account->username);
                        //如果會員不存在就重新綁帳號
                        if (isset($result['message']['ResultCode']) && $result['message']['ResultCode'] == '3') {
                            $rebind_account = true;
                            $vendor_account = new VplayAccount();
                            $vendor_account->find($account->id)
                                ->update(
                                    [
                                        'member_id' => -$account->member_id
                                    ]
                                );
                            if ($account = $vendor_account->where('member_id', 0)->first()) {
                                $vendor_account->find($account->id)->update(array(
                                    'member_id' => $memberId
                                ));
                                $result = $vendor->getBalance($account->username);
                            }
                        }
                        break;
                    //鬥雞
                    case 9:
                        $vendor = new VendorCokeFighting();
                        $result = $vendor->getBalance($account->username);
                        break;
                    //mega888
                    case 10:
                        $vendor = new VendorMega888($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    //xe88
                    case 11:
                        $vendor = new VendorXe88($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //lucky
                    case 12:
                        $vendor = new VendorLucky($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    //ebet
                    case 13:
                        $vendor = new VendorEbet($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //iSB
                    case 14:
                        $vendor = new VendorIsb($info);
                        $result = $vendor->getBalance($account->username, $account->player_id);
                        break;
                    //Ygg
                    case 15:
                        $vendor = new VendorYgg($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //Wm
                    case 16:
                        $vendor = new VendorWm($info);
                        $result = $vendor->getBalance($account->user_id);
                        break;
                    //PlayTech
                    case 17:
                        $vendor = new VendorPT($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //SBO
                    case 18:
                        $vendor = new VendorSbo($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //MT
                    case 19:
                        $vendor = new VendorMt($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //Pg
                    case 20:
                        $vendor = new VendorPg($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //小艾
                    case 21:
                        $vendor = new VendorIa($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //Vpower
                    case 22:
                        $vendor = new VendorVpower($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 23:
                        $vendor = new VendorPussy888($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    case 24:
                        $vendor = new VendorPgs($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //AE Sexy
                    case 25:
                        $vendor = new VendorAes($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //IGS
                    case 26:
                        $vendor = new VendorIgs($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    case 27:
                        $vendor = new VendorCt($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 28:
                        $vendor = new VendorSky1388($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 29:
                        $vendor = new VendorDg($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 30:
                        $vendor = new VendorPlay8oy($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 31:
                        $vendor = new VendorSkybet($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 32:
                        $vendor = new VendorRm($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 33:
                        $vendor = new VendorN918($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    case 34:
                        $vendor = new VendorTerra($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 35:
                        $vendor = new VendorAllbet($info);
                        $password = [REDACTED], $account->created_at);
                        $result = $vendor->getBalance($account->username, $password);
                        break;
                    case 36:
                        $vendor = new VendorVSPoker($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 37:
                        $vendor = new VendorSa($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 38:
                        $vendor = new VendorEsport($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 39:
                        $vendor = new VendorFishing($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 40:
                        $vendor = new VendorMenang88($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    case 41:
                        $vendor = new VendorNpt($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 42:
                        $vendor = new VendorV8($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 43:
                        $vendor = new VendorObet33($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 44:
                        $vendor = new VendorSaba($info);
                        $result = $vendor->getBalance($account->username_modified);
                        break;
                    case 45:
                        $vendor = new VendorBola2($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 46:
                        $vendor = new VendorPlus100($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 47:
                        $vendor = new VendorSpadeGaming($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 48:
                        $vendor = new VendorNs($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 49:
                        $vendor = new VendorBng($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 51:
                        $vendor = new VendorMgc($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 52:
                        $vendor = new VendorYbet($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    case 53:
                        $vendor = new VendorVsport($info);
                        $result = $vendor->getBalance($account->username);
                        break;
                    //Testgame
                    case 99:
                        $vendor = new VendorTestgame($info);
                        $result = $vendor->getBalance($account->player_id);
                        break;
                    default:
                        return [
                            'code' => 9994,
                            'message' => __('error_message.9994')
                        ];
                        break;
                }
            }

            if ($rebind_account == true) {
                $message = "[" . $vendor->getName() . "重新綁定帳號]" . PHP_EOL .
                    "Member Id:" . $memberId . PHP_EOL .
                    "Old Account:" . $old_account . PHP_EOL .
                    "New Account:" . $account->username;
                $this->tgService->sendMessage($message, 'warn');
            }
        }
        return $result;
    }

    /**
     * resetVendorPassword
     * -取遊戲商登入資訊(需要另外登入的遊戲商才適用)
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     * @bodyParam new_password string required 新密碼(6-14位數，只可輸入英數字)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function resetPassword(Request $request)
    {
        $rule = [
            'vendor_id' => 'required|digits_between:0,10',
            'new_password' => 'required|r_alpha_num|between:6,14'
        ];
        $validator = Validator::make($request->all(), $rule, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $row = Vendor::where('need_login', 1)->where('id', $request->vendor_id)->first();
        if (!is_object($row)) {
            // 查無此遊戲商或此遊戲商不需另外登入
            return response()->json([
                'code' => 9994,
                'message' => __('error_message.9994')
            ]);
        } else {
            list($account, $info) = $this->getVendorAccount($request->ID, $row->id);
            if (!is_object($account)) {
                return response()->json($account);
            } else {
                $password = [REDACTED], $account->created_at);
                switch ($row->id) {
                    case 3: // SCR(918)
                        $vendor = new VendorScr($info);
                        $result = $vendor->resetPassword($account, $request->new_password);
                        break;
                    case 5: // GW99
                        $vendor = new VendorGreatWall($info);
                        $result = $vendor->resetPassword($account->username, $request->new_password);
                        break;
                    case 6: //ACE333(密碼存在我們這，所以不用去打api)
                        $vendor = new VendorAce333($info);
                        $result = $vendor->resetPassword($account->username, $request->new_password);
                        break;
                    case 10:
                        $vendor = new VendorMega888($info);
                        $result = $vendor->resetPassword($account->username, $request->new_password);
                        break;
                    case 11:
                        $vendor = new VendorXe88($info);
                        $result = $vendor->resetPassword($account->username, $request->new_password);
                        break;
                    default:
                        $result = [
                            'code' => 9994,
                            'message' => __('error_message.9994')
                        ];
                        break;
                }
                return response()->json($result);
            }
        }
    }

    /**
     * vendorInfo
     * -取遊戲商登入資訊(需要另外登入的遊戲商才適用)
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "username": "遊戲商帳號,ex:xxx",
     *        "password": "遊戲商密碼,ex:123456",
     *        "ios_download": "iOS下載連結,ex:https://xxx.x.xxx.x",
     *        "ios_open": "iOS打開APP,ex:lobby://xxxxx",
     *        "android_download": "Android下載連結,ex:https://xxx.x.xxx.x",
     *        "android_open": "Android打開APP,ex:lobby://xxxxx",
     *        "app_icon": "APP圖示,ex:https://xxx.x.xxx.x"
     *    },
     * "data(豪龍)": {
     *      "url": "https://example.invalid/bet.aspx?user=testplaxsgx00000009&sessionID=3gkh5b0lvly4kwmp2iy4gigb&tokenCode=0A52C1DA-85FF-44E5-964F-E4FA383D6B3E"
     *      },
     * "data(鬥雞)": {
     *      "url": "https://example.invalid/api/player/gdc168/login",
     *      "user": "dev00000009",
     *      "key": "c11JgLJRCSeKj+mpfjZWfVhCiBfNoz17xwWIU1TQkQp60Wsp2MaEUMioYp4pY4ad",
     *      "extension1": "gm123456"
     *      }
     * }
     */
    public function vendorInfo(Request $request)
    {
        $rule = [
            'vendor_id' => 'required|digits_between:0,10'
        ];
        $validator = Validator::make($request->all(), $rule, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $model = Vendor::where('id', $request->vendor_id)
            ->where(function ($query) {
                $query->orWhere('need_login', 1)
                    ->orWhere('only_one_game', 1);
            });
        // 依玩家設備判斷是否顯示
        if (isset($request->is_app) && $request->is_app == 1) {
            //APP版顯示的遊戲商
            $model->where('show_on_app', 1);
        } elseif ($request->IS_MOBILE) {
            //手機版顯示的遊戲商
            $model->where('show_on_mobile', 1);
        } else {
            //PC版顯示的遊戲商
            $model->where('show_on_pc', 1);
        }
        $row = $model->first();

        if (!is_object($row)) {
            // 查無此遊戲商或此遊戲商不需另外登入
            return response()->json([
                'code' => 9994,
                'message' => __('error_message.9994')
            ]);
        } else {
            list($account, $info) = $this->getVendorAccount($request->ID, $row->id);
            if (!is_object($account)) {
                return response()->json($account);
            } else {
                $password = [REDACTED], $account->created_at);
                $language = Language::where('id', $request->LANGUAGE)->first();
                switch ($row->id) {
                        // Joker
//                    case 2:
//                        $vendor = new VendorJoker($info);
//                        $username = $account->username;
//                        $vendor->resetPassword($username, $password);
//                        $encrypt_data = $vendor->getAppEncryptData($username, $password);
//                        $result = array(
//                            'code' => 1000,
//                            'message' => __('error_message.1000'),
//                            'data' => [
//                                'username' => $username,
//                                'password' => $password,
//                                'ios_download' => env('JOKER_DOWNLOAD_PAGE_IOS'),
//                                'ios_open' => env('JOKER_APP_SCHEMA') . $encrypt_data,
//                                'android_download' => env('JOKER_DOWNLOAD_PAGE_ANDORID'),
//                                'android_open' => env('JOKER_APP_SCHEMA') . $encrypt_data,
//                                'app_icon' => $row->app_icon,
//                            ]
//                        );
//                        break;
                        // SCR(918)
                    case 3:
                        $vendor = new VendorScr($info);
                        $username = $account->player_id;
                        $password = [REDACTED] && $account->password != '') ? $account->password : [REDACTED]' . $password;
                        // 將密碼修改為預設的
                        // $vendor->resetPassword($account->username, $account->player_id, $password);
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'username' => $username,
                                'password' => $password,
                                'ios_download' =>$info->DOWNLOAD_PAGE,
                                'ios_open' => 'LobbyKiss64://account=' . $username . '&password=' . $password,
                                'android_download' => $info->DOWNLOAD_PAGE,
                                'android_open' => 'lobbykiss://lobbykiss?account=' . $username . '&password=' . $password,
                                'app_icon' => $row->app_icon
                            ]
                        );
                        break;
                        //豪龍
                    case 4:
                        if (isset($account->is_vworld) && $account->is_vworld == 1) {
                            $password = [REDACTED]';
                        }
                        $vendor = new VendorHaolong($info);
                        $result = $vendor->getGameUrl($account->username, $password);
                        if ($result['code'] == 1000) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        }
                        break;
                        // GW99
                    case 5:
                        $vendor = new VendorGreatWall($info);
                        $url = $vendor->getAppUrl();
                        $username = $info->API_AGENT . $account->username;
                        // 將密碼修改為預設的
                        $vendor->resetPassword($account->username, $password);
                        if ($url['code'] == 1000) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'username' => $username,
                                    'password' => $password,
                                    'ios_download' => $url['data']['iOS'],
                                    'ios_open' => 'lobbygw99://lobbygw99?account=' . $username . '&password=' . $password,
                                    'android_download' => $url['data']['Android'],
                                    'android_open' => 'lobbygw99://lobbygw99?account=' . $username . '&password=' . $password,
                                    'app_icon' => $row->app_icon,
                                ]
                            );
                        } else {
                            $result = $url;
                        }
                        break;
                        //ace333
                    case 6:
                        $vendor_info = VendorInfo::find($account->vendor_info_id);
                        $info = json_decode($vendor_info->info);
                        $username = $account->username;
                        //TODO 目前先用vwrold驗證, 所以帳密依vworld規則
                        // $password = 'Aa' . (6489 + substr($username, -4));
                        //2021-12-28 因為有多商戶所以要更改 by wintoto
                        $username = $username . $info->POSTFIX;

                        if ($account->password == '') {
                            //未設置密碼
                            $password = [REDACTED]'error_message.9009');
                        } else {
                            //已設置過密碼
                            $password = [REDACTED]'error_message.9010');
                        }
                        //密碼會是來我們這驗證的，所以不會被使用者修改
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'username' => $username,
                                'password' => $password,
                                'ios_download' => $info->DOWNLOAD_URL,
                                'ios_open' => '',
                                'android_download' => $info->DOWNLOAD_URL,
                                'android_open' => '',
                                'app_icon' => $row->app_icon,
                            ]
                        );
                        break;
                        //鬥雞
                        // case 7:
                        //     $vendor = new VendorChichenGame();
                        //     $result = $vendor->getGameUrl($account->username, $password);
                        //     break;
                        // VPLAY
                    case 8:
                        $username = $account->username;
                        $timestamp = date('U');
                        $hash = strtoupper(md5($info->AGENT . $username . $timestamp . $info->SECRET));
                        if ($request->LANGUAGE == 1) {
                            $language = 'en-US';
                        } else {
                            $language = 'zh-CN';
                        }

                        $scheme_url = 'vworldtogame://' . $info->GAME_PLATFORM . '?agentaccount='  . $info->AGENT . '&memberaccount=' . $username . '&timestamp=' . $timestamp . '&hash=' . $hash . '&domain=' . $info->DOMAIN . '&language=' . $language;
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'username' => $username,
                                'password' => $password,
                                'ios_download' => 'itms-services://?action=download-manifest&url=https://example.invalid/APPDL/IOS/Browser/VWorld.plist',
                                'ios_open' => $scheme_url,
                                'android_download' => 'https://example.invalid/APPDL/Android/Browser/VWorld.apk',
                                'android_open' => $scheme_url,
                                'app_icon' => $row->app_icon,
                            ]
                        );
                        break;
                        // 鬥雞coke fighting
                    case 9:
                        $vendor = new VendorCokeFighting();
                        $result = $vendor->getGameUrl($account->username);
                        break;
                        // Mega888
                    case 10:
                        $vendor = new VendorMega888($info);
                        $url = $vendor->getAppUrl();
                        $username = $account->player_id;
                        if ($account->password == '') {
                            //未設置密碼
                            $password = [REDACTED]'error_message.9009');
                        } else {
                            //已設置過密碼
                            $password = [REDACTED]'error_message.9010');
                        }
                        if ($url['code'] == 1000) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'username' => $username,
                                    'password' => $password,
                                    'ios_download' => $info->DOWNLOAD_URL,
                                    'ios_download2' => $info->DOWNLOAD_URL2,
                                    'ios_open' => '',
                                    'android_download' => $info->DOWNLOAD_URL,
                                    'android_download2' => $info->DOWNLOAD_URL2,
                                    'android_open' => '',
                                    'app_icon' => $row->app_icon,
                                ]
                            );
                        } else {
                            $result = $url;
                        }
                        break;
                        //SBO
                    case 18:
                        $vendor = new VendorSbo($info);
                        $result = $vendor->getGameUrl($account->username, 'sbo_2', $language->sbo);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                        //vpower
                    case 22:
                        $vendor = new VendorVpower($info);
                        $result = $vendor->getAppUrl($account->username);
                        if (isset($result['code']) && $result['code'] == 1000) {
                            $scheme_url = 'vpower://token=' . $result['data'];
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'ios_download' => isset($info->IOS_DOWNLOAD) ? $info->IOS_DOWNLOAD : '',
                                    'ios_open' => $scheme_url,
                                    'android_download' => isset($info->ANDROID_DOWNLOAD) ? $info->ANDROID_DOWNLOAD : '',
                                    'android_open' => $scheme_url,
                                    'app_icon' => '',
                                ]
                            );
                        }
                        break;
                        //Pussy888
                    case 23:
                        $username = $account->player_id;
                        $password = [REDACTED]';
                        // 將密碼修改為預設的
                        // $vendor->resetPassword($account->username, $account->player_id, $password);
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'username' => $username,
                                'password' => $password,
                                'ios_download' => $info->DOWNLOAD_URL,
                                'ios_open' => "pussy888://example.invalid/?user=$username&password=[REDACTED]",
                                'android_download' => $info->DOWNLOAD_URL,
                                'android_open' => "pussy888://example.invalid/?user=$username&password=[REDACTED]",
                                'app_icon' => $row->app_icon,
                            ]
                        );
                    break;
                    case 29:
                        $vendor = new VendorDg($info);
                        $result = $vendor->getGameUrl($account->username, $language->dg);
                        if (isset($result['code']) && $result['code'] == 1000) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['url']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    // Play8oy
                    case 30:
                        $vendor = new VendorPlay8oy($info);
                        $url = $vendor->getAppUrl();
                        $username = $account->username;
                        // 將密碼修改為預設的
                        $vendor->resetPassword($account->username, $password);
                        if ($url['code'] == 1000) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'username' => $username,
                                    'password' => $password,
                                    'ios_download' => $url['data']['iOS'],
                                    'ios_open' => 'lobbyplay8oy://lobbyplay8oy?account=' . $username . '&password=' . $password,
                                    'android_download' => $url['data']['Android'],
                                    'android_open' => 'lobbyplay8oy://lobbyplay8oy?account=' . $username . '&password=' . $password,
                                    'app_icon' => $row->app_icon,
                                ]
                            );
                        } else {
                            $result = $url;
                        }
                        break;
                    case 31:
                        $vendor = new VendorSkybet($info);
                        $result = $vendor->getGameUrl($account->username);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 33:
                        $username = $account->player_id;
                        $password = [REDACTED]';
                        // 將密碼修改為預設的
                        // $vendor->resetPassword($account->username, $account->player_id, $password);
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'username' => $username,
                                'password' => $password,
                                'ios_download' =>$info->DOWNLOAD_PAGE,
                                'ios_open' => 'LobbyKiss64://account=' . $username . '&password=' . $password,
                                'android_download' => $info->DOWNLOAD_PAGE,
                                'android_open' => 'lobbykissandroid://lobbykissandroid?account=' . $username . '&password=' . $password,
                                'app_icon' => $row->app_icon,
                            ]
                        );
                        break;
                    case 38:
                        $vendor = new VendorEsport($info);
                        $result = $vendor->getGameUrl($account->username, 'sbo_2');
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 40:
                        $vendor = new VendorMenang88($info);
                        $result = $vendor->getGameUrl($account->player_token);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 43:
                        $vendor = new VendorObet33($info);
                        $result = $vendor->getGameUrl($account->username, $language->obet33);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 44:
                        $vendor = new VendorSaba($info);
                        $result = $vendor->getGameUrl($account->username_modified, $language->saba, $request->IS_MOBILE);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 45:
                        $vendor = new VendorBola2($info);
                        $result = $vendor->getGameUrl($account->username, $password);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    case 51:
                        $vendor = new VendorMgc($info);
                        $result = $vendor->getAppUrl($account->username);
                        if (isset($result['code']) && $result['code'] == 1000) {
                            $scheme_url = $info->DEEPLINK . '?data=' . $result['data'];
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'ios_download' => isset($info->IOS_DOWNLOAD) ? $info->IOS_DOWNLOAD : $info->DOWNLOAD_URL,
                                    'ios_open' => $scheme_url,
                                    'android_download' => isset($info->ANDROID_DOWNLOAD) ? $info->ANDROID_DOWNLOAD : $info->DOWNLOAD_URL,
                                    'android_open' => $scheme_url,
                                    'app_icon' => '',
                                ]
                            );
                        }
                        break;
                    case 53:
                        $vendor = new VendorVsport($info);
                        $result = $vendor->getGameUrl($account->username, $language->vsport);
                        if (isset($result['data'])) {
                            $result = array(
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'url' => $result['data']
                                ]
                            );
                        } else {
                            return $result;
                        }
                        break;
                    default:
                        $result = [
                            'code' => 9994,
                            'message' => __('error_message.9994')
                        ];
                        break;
                }
                return response()->json($result);
            }
        }
    }

    /**
     * getGameUrl
     * - 取遊戲連結
     *
     * @bodyParam game_id interger required 遊戲系統編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "https:\/\/example.invalid\/forwardGame.do?params=zC8r+rGZDg...",
     *  "url": {
     *        "mobile": "手機版網址,ex:https://example.invalid/forwardGame.do?params=zC8r+rGZDg...",
     *        "pc": "電腦版網址,ex:https://example.invalid/forwardGame.do?params=zC8r+rGZDg..."
     *    },
     *  "event": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "title": "活動名稱,ex:多玩多送"
     *        },
     *        {
     *            "id": 2,
     *            "title": "贏多多"
     *        }
     *    ]
     * }
     * @response  {
     *  "code": 1000,
     *  "message": "成功",
     *  "data(PT)": {
     *      "username": "ELGSMYRDEV00011",
     *      "passowrd": "242354",
     *      "game_type": "ftsis",
     *      "mobile_domain": "example.invalid",
     *      "system_id": "424",
     *      "language": "zh-cn"
     *  }
     * }
     */
    public function getGameUrl(Request $request)
    {
        $rule = [
            'game_id' => 'required|digits_between:0,10'
        ];
        $validator = Validator::make($request->all(), $rule, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }

        //查vendor_info_ids
        $vendor_info_ids = $this->vendorList($request->ID, 'a.vendor_info_id');

        // 取遊戲資訊
        $game = DB::table('games as g')
            ->join('vendor_info_games as vig', function ($join) use ($vendor_info_ids) {
                $join->on('vig.game_id', 'g.id')
                    ->where('vig.status', 1)
                    ->whereIn('vig.vendor_info_id', $vendor_info_ids);
            })
            ->where('g.id', $request->game_id)
            ->where('g.status', 1)
            ->select(
                'g.vendor_id',
                'g.game_type',
                'vig.join_bet_event',
                'vig.join_win_event',
                'g.remark',
                'example.invalid'
            )
            ->first();

        if (!is_object($game)) {
            return response()->json([
                'code' => 1406,
                'message' => __('error_message.1406')
            ]);
        } else {
            //取遊戲商資訊
            $vendor = Vendor::where('id', $game->vendor_id);
            // 依玩家設備判斷是否顯示
            if (isset($request->is_app) && $request->is_app == 1) {
                //APP版顯示的遊戲商
                $vendor->where('show_on_app', 1);
            } elseif ($request->IS_MOBILE) {
                //手機版顯示的遊戲商
                $vendor->where('show_on_mobile', 1);
            } else {
                //PC版顯示的遊戲商
                $vendor->where('show_on_pc', 1);
            }
            $vendor = $vendor->first();
            if (!is_object($vendor)) {
                //此遊戲商不可使用此設備開啟
                return response()->json([
                    'code' => 14061,
                    'message' => __('error_message.1406')
                ]);
            } else {
                $open_window = $vendor->open_window;
                list($account, $info) = $this->getVendorAccount($request->ID, $game->vendor_id);
                if (!is_object($account)) {
                    return response()->json($account);
                } else {
                    $password = [REDACTED], $account->created_at);
                    $language = Language::where('id', $request->LANGUAGE)->first();
                    switch ($game->vendor_id) {
                        case 1:
                            if (isset($account->password) && $account->password != '') {
                                $password = [REDACTED], 0, 20);
                            }
                            // ag帳號密碼
                            $vendor = new VendorAg($info);
                            // sid唯一的13~16碼的數字 Example: 1234567890123
                            $sid = date('U') . rand(1000, 9999);
                            $result = $vendor->forwardGame($account->username, $password, $sid, $game->game_type, $language->ag);
                            break;
                            // Joker
                        case 2:
                            $vendor = new VendorJoker($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type);
                            break;

                            //豪龍
                        case 4:
                            if (isset($account->is_vworld) && $account->is_vworld == 1) {
                                $password = [REDACTED]';
                            }
                            $vendor = new VendorHaolong($info);
                            $result = $vendor->getGameUrl($account->username, $password);
                            break;
                            // XE88
                        case 11:
                            $vendor = new VendorXE88($info);
                            $vendor->resetPassword($account->username, $password);
                            $result = $vendor->getGameUrl($account->username, $password, $game->game_type, $language->xe88);
                            break;
                            // Lucky
                        case 12:
                            $vendor = new VendorLucky($info);
                            $result = $vendor->getGameUrl($account->player_id, $game->game_type, $language->lucky);
                            break;
                            // eBET
                        case 13:
                            $token = [REDACTED], $account->updated_at); //每次變動降低被盜網址的危險
                            $vendor = new VendorEbet($info);
                            $result = $vendor->getGameUrl($account->username, $token, $game->game_type, $language->ebet, $game->remark);
                            break;
                            // iSB
                        case 14:
                            $vendor = new VendorIsb($info);
                            $result = $vendor->getGameUrl($account->username, $account->player_id, $game->game_type, $language->isb);
                            break;
                            // Ygg
                        case 15:
                            $vendor = new VendorYgg($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->ygg);
                            break;
                            //Wm
                        case 16:
                            $vendor = new VendorWm($info);
                            $result = $vendor->getGameUrl($account->user_id, $game->game_type, $language->wm, $game->info);
                            break;
                            //PT
                        case 17:
                            $vendor = new VendorPT($info);
                            $result = $vendor->getGameUrl($account->username, $password, $game->game_type, $language->pt);
                            break;
                            //SBO
                        case 18:
                            $vendor = new VendorSbo($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->sbo);
                            break;
                            //MT
                        case 19:
                            $vendor = new VendorMt($info);
                            $result = $vendor->getGameUrl($account->username, $password, $game->game_type, $language->mt);
                            break;
                            //Pg
                        case 20:
                            $vendor = new VendorPg($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->pg);
                            break;
                            //小艾
                        case 21:
                            $vendor = new VendorIa($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->ia);
                            break;
                            //Vpower
                        case 22:
                            $vendor = new VendorVpower($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->ia);
                            break;
                        case 24:
                            $vendor = new VendorPgs($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->target);
                            break;
                            //AE Sexy
                        case 25:
                            $vendor = new VendorAes($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->aes, $request->IS_PAD);
                            break;
                            //IGS
                        case 26:
                            $vendor = new VendorIgs($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->igs);
                            break;
                        case 27:
                            $vendor = new VendorCt($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->ct);
                            break;
                        case 28:
                            $vendor = new VendorSky1388($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->sky1388);
                            break;
                        case 29:
                            $vendor = new VendorDg($info);
                            $result = $vendor->getGameUrl($account->username, $language->dg);
                            break;
                        case 30:
                            $vendor = new VendorPlay8oy($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->dg);
                            break;
                        case 31:
                            $vendor = new VendorSkybet($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->dg);
                            break;
                        case 32:
                            $vendor = new VendorRm($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->rm);
                            break;
                        case 34:
                            $vendor = new VendorTerra($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->terra);
                            break;
                        case 35:
                            $vendor = new VendorAllbet($info);
                            $result = $vendor->getGameUrl($account->username, $password, $game->game_type, $language->allbet);
                            break;
                        case 36:
                            $vendor = new VendorVSPoker($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type);
                            break;
                        case 37:
                            $vendor = new VendorSa($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->sa);
                            break;
                        case 38:
                            $vendor = new VendorEsport($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type);
                            break;
                        case 39:
                            //需要額外用我們的登入token做驗證，所以要查現在的user是使用哪個token
                            $token = [REDACTED]'member_id', $account->member_id)
                                ->orderBy('id', 'DESC')
                                ->pluck('token')
                                ->first();
                            $vendor = new VendorFishing($info);
                            $result = $vendor->getGameUrl($account->username, $token, $language->fishing);
                            break;
                        case 40:
                            $vendor = new VendorMenang88($info);
                            $result = $vendor->getGameUrl($account->player_token);
                            break;
                        case 41:
                            $vendor = new VendorNpt($info);
                            $result = $vendor->getGameUrl($account->username_modified, $password, $game->game_type, $game->remark, $language->npt);
                            break;
                        case 42:
                            $vendor = new VendorV8($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->v8);
                            break;
                        case 43:
                            $vendor = new VendorObet33($info);
                            $result = $vendor->getGameUrl($account->username, $language->obet33);
                            break;
                        case 44:
                            $vendor = new VendorSaba($info);
                            $result = $vendor->getGameUrl($account->username_modified, $language->saba, $request->IS_MOBILE);
                            break;
                        case 45:
                            $vendor = new VendorBola2($info);
                            $result = $vendor->getGameUrl($account->username, $password, $language->bola2);
                            break;
                        case 46:
                            $vendor = new VendorPlus100($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->plus100);
                            break;
                        case 47:
                            $token = [REDACTED]'member_id', $account->member_id)
                                ->orderBy('id', 'DESC')
                                ->pluck('token')
                                ->first();
                            $vendor = new VendorSpadeGaming($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $token, $language->spadegaming);
                            break;
                        case 48:
                            $token = [REDACTED]'member_id', $account->member_id)
                                ->orderBy('id', 'DESC')
                                ->pluck('token')
                                ->first();
                            $vendor = new VendorNs($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $token, $language->ns);
                            break;
                        case 49:
                            $vendor = new VendorBng($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->bng);
                            break;
                        case 50:
                            $vendor = new VendorPgssw($info);
                            $result = $vendor->getGameUrl($account->id, $account->username, $game->game_type, $language->target);
                            break;
                        case 52:
                            $vendor = new VendorYbet($info);
                            $result = $vendor->getGameUrl($account->username, $game->game_type, $language->target);
                            break;
                            //Testgame
                        case 99:
                            $vendor = new VendorTestgame($info);
                            $result = $vendor->getGameUrl($account->player_id);
                        break;
                        default:
                            $result = [
                                'code' => 9994,
                                'message' => __('error_message.9994')
                            ];
                            break;
                    }

                    if ($result['code'] != 9994) {
                        $result['open_window'] = $open_window;
                    }

                    //先判斷遊戲是否可參加活動
                    if ($game->join_bet_event == 1 || $game->join_win_event == 1) {
                        //加上有參與的活動資料
                        // 取活動的Detail,每個遊戲商參加了什麼活動
                        $agent = DB::table('agents as a')
                            ->leftJoin('members as m', 'm.parent', 'a.id')
                            ->where('m.id', $request->ID)
                            ->select('a.id', 'a.hierarchy')
                            ->first();
                        $hierarchy = explode(',', $agent->hierarchy);

                        $events = DB::table('events as e')
                            ->leftJoin('event_details as d', 'd.event_id', 'e.id')
                            ->leftJoin('event_names as n', function ($join) use ($request) {
                                $join->on('n.event_id', 'e.id')
                                    ->where('n.language_id', $request->LANGUAGE);
                            })
                            ->whereIn('e.agent_id', $hierarchy) //可參加自己上線及平台辦的活動
                            ->where('d.vendor_id', $game->vendor_id)
                            ->where(function ($query) use ($game) {
                                //參加投注
                                if ($game->join_bet_event == 1) {
                                    $query->where('d.action_id', 2);
                                }
                                if ($game->join_win_event == 1) {
                                    //參加贏分活動
                                    $query->orWhere('d.action_id', 3);
                                }
                            })
                            ->where('d.status', 1)
                            ->where('e.status', 1)
                            ->where('d.start_time', '<', date('Y-m-d H:i:s'))
                            ->where('d.end_time', '>', date('Y-m-d H:i:s'))
                            ->orderBy('e.id')
                            ->select(
                                'e.id',
                                DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as title")
                            )
                            ->get();
                    }
                    return response()->json($result);
                }
            }
        }
    }

    /**
     * transactions
     * - 轉點記錄列表
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     * @bodyParam start_time dateTime 狀態 Example: 2019-05-01 00:00:00
     * @bodyParam end_time dateTime 狀態 Example: 2019-05-05 23:59:59
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商系統編號,ex:2",
     *            "vendor_name": "遊戲商名稱,ex:Joker",
     *            "type": "類型1:轉入遊戲商2:轉入平台,ex:1",
     *            "point": "平台點數,ex:100",
     *            "vendor_point": "遊戲商點數,ex:100",
     *            "balance": "平台點數餘額,ex:0",
     *            "vendor_balance": "遊戲商點數餘額,ex:100",
     *            "rate": "比值(平台點數:遊戲商點數,rate:1),ex:1",
     *            "status": "狀態1000成功, 其他看result,ex:1000",
     *            "result": "結果",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *        },
     *        {
     *            "id": 2,
     *            "vendor_id": 1,
     *            "vendor_name": "AG",
     *            "type": 2,
     *            "point": 100,
     *            "vendor_point": 50,
     *            "balance": 100,
     *            "vendor_balance": 50,
     *            "rate": "2",
     *            "status": "1000",
     *            "result": "{\"balance\":100,\"vendor_balance\":50}",
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28"
     *        }
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
    public function transactions(Request $request)
    {
        try {
            $model = new TransactionLog();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $key = array(
                'transaction',
                'list',
                $request->vendor_id,
                $request->start_time,
                $request->end_time,
                $request->ID,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'transaction',
                'list',
                $request->vendor_id,
                $request->start_time,
                $request->end_time,
                $request->ID,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $vendorImage = DB::table('vendor_images as vi')
                    ->join('agent_groups as ag', 'ag.id', 'vi.agent_group_id')
                    ->join('agents as a', 'a.vendor_customize_group_id', 'ag.id')
                    ->join('members', function ($join) use ($request) {
                        $join->on('members.parent', 'a.id')
                            ->where('members.id', $request->ID);
                    })
                    ->select('vi.*');
                $bindings = $vendorImage->getBindings();
                $vendorImageSql = str_replace('?', '%s', $vendorImage->toSql());
                $vendorImageSql = sprintf($vendorImageSql, ...$bindings);

                $model = $model->leftJoin('vendors as v', 'v.id', 'transaction_logs.vendor_id')
                ->leftJoin(DB::raw("(".$vendorImageSql.") AS b" ), "b.vendor_id", "v.id");
                if (isset($request->vendor_id) && $request->vendor_id != '') {
                    $model = $model->where('vendor_id', $request->vendor_id);
                }
                if (isset($request->start_time) && $request->start_time != '') {
                    $model = $model->where(
                        'transaction_logs.created_at',
                        '>=',
                        date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'))
                    );
                }
                if (isset($request->end_time) && $request->end_time != '') {
                    $model = $model->where(
                        'transaction_logs.created_at',
                        '<=',
                        date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))
                    );
                }
                // order_id才是轉點，其他是訂單增加平台點數
                $model = $model->where('member_id', $request->ID)
                    ->where('transaction_logs.status', 1000)
                    ->whereIn('type', [1, 2]);
                $total = $model->count();
                $data = $model->orderBy('transaction_logs.created_at', 'desc')
                    ->orderBy('transaction_logs.id', 'desc')
                    ->select(
                        'transaction_logs.id',
                        'transaction_logs.vendor_id',
                        DB::raw('(CASE WHEN b.name IS NULL THEN v.name ELSE b.name END) as vendor_name'),
                        'type',
                        'point',
                        'vendor_point',
                        'balance',
                        'vendor_balance',
                        'transaction_logs.rate',
                        'transaction_logs.status',
                        'result',
                        DB::raw('DATE_ADD(transaction_logs.created_at,INTERVAL 8 HOUR) as created_at'),
                        DB::raw('DATE_ADD(transaction_logs.updated_at,INTERVAL 8 HOUR) as updated_at')
                    )
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
     * balanceToVendor
     * -平台餘額轉帳至遊戲商餘額
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     * @bodyParam point interger required 轉入金額
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {"balance":"100","vendor_balance":"200"}
     * }
     */
    public function balanceToVendor(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rule(), $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $result = $this->vendorTransfer($request->vendor_id, $request->ID, $request->point, 1);
        return response()->json($result);
    }

    /**
     * vendorToBalance
     * - 遊戲商餘額轉帳至平台餘額
     *
     * @bodyParam vendor_id interger required 遊戲商系統編號
     * @bodyParam point interger required 轉出金額
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {"balance":"100","vendor_balance":"200"}
     * }
     */
    public function vendorToBalance(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rule(), $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $result = $this->vendorTransfer($request->vendor_id, $request->ID, $request->point, 2);
        return response()->json($result);
    }

    /**
     * 共用的遊戲商轉入轉出
     * @param $vendorId int 遊戲商ID
     * @param $memberId int 玩家ID
     * @param $point float 轉帳點數
     * @param $type int 轉帳類型; 1: 轉入, 2: 轉出
     * @return object
     */
    public function vendorTransfer($vendorId, $memberId, $point, $type)
    {
        $transferVendor = Vendor::find($vendorId);
        $result = false;
        //超過1000時的千分位要拿掉，不然會有問題
        $point = str_replace(',', '', $point);
        if ($point > 0) {
            if($transferVendor->is_single_wallet == 0){
                $info = $this->preTransaction($vendorId, $memberId, $type);
                if (isset($info['username'])) {
                    $result = $this->balanceTransaction(
                        $memberId,
                        $vendorId,
                        $info['username'],
                        $info['password'],
                        $info['vendor'],
                        $type,
                        $info['balance'],
                        $point
                    );
                } else {
                    $result = $info;
                }
            } else {
                //如果是單錢包遊戲 就直接回傳成功
                $balance = ($type ==1) ? 0 : $point;
                $vendor_balance = ($type ==1) ? $point : 0;
                $result =  array(
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => [
                        'balance' => $balance,
                        'vendor_balance' => $vendor_balance
                    ]
                );
            }
        }

        if ($type == 1) {
            //判斷返回結果
            $result = $this->getVendorTransferOutResult($result, $point);
        }
        return $result;
    }

    /**
     * 查詢最後一間轉點的遊戲商, 轉入的訂單
     */
    public function lastTransactionVendor($memberId)
    {
        $data = TransactionLog::select('vendor_id', 'type')
            ->where('member_id', $memberId)
            ->where('type', 1)
            ->where('status', 1000)
            ->orderBy('created_at', 'DESC')
            ->first();
        if (is_object($data)) {
            //回傳遊戲商
            return ['vendor_id' => $data->vendor_id, 'type' => $data->type];
        } else {
            //如果沒轉過點，就回傳0
            return ['vendor_id' => 0, 'type' => 0];
        }
    }

    /**
     * singleWalletBalance
     * - 單一錢包餘額
     *
     * @bodyParam vendor_id interger 遊戲商系統編號(如果沒帶就是查平台餘額)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "0.00"
     * }
     *
     * @response {
     *  "code": 99961,
     *  "message": "遊戲商回應失敗",
     *  "data": "只有平台或單一遊戲商餘額，EX:0.00"
     * }
     */
    public function singleWalletBalance(Request $request)
    {
        $rule = [
            'vendor_id' => 'digits_between:0,10'
        ];
        $validator = Validator::make($request->all(), $rule, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        $result = $this->getSingleWalletBalanceNew($request);
        return response()->json($result);
        Common::log($request->ID, $result, 'getSingleWalletBalance');
        if (is_array($result) && isset($result['data']) && is_numeric($result['data'])) {
            $result['data'] = number_format($result['data'], 4);
        }
        return response()->json($result);
    }

    // 遊戲商額度轉回本地錢包
    private function vendorBalanceToPlatform($vendor_id, $member_id)
    {
        try {
            // 1. 先從VendorFail 確認遊戲商是否關閉轉點, 關閉轉點則直接 return
            if (VendorRequestFail::where('vendor_id', $vendor_id)->where('status', 0)->exists()) {
                return true;
            }
            // 2. 準備轉帳所需資訊
            $account_info = $this->preTransaction($vendor_id, $member_id, 2);
            if (isset($account_info['username'])) {
                $vendor = $account_info['vendor'];
                // 3. 轉點前先取餘額，沒取到就暫時關閉此遊戲商轉點
                $vendor_balance = $vendor->getBalance($account_info['username'], $account_info['password']);
                if ($vendor_balance['code'] == 1000) {
                    $point = str_replace(',', '', $vendor_balance['data']);
                    // 4. 檢查轉點點數, 不符合條件則直接 return
                    if (!$this->canVendorPointTransfer($vendor_id, $point)) {
                        return true;
                    }
                    // 5. 進行轉點
                    return $this->balanceTransaction(
                        $member_id,
                        $vendor_id,
                        $account_info['username'],
                        $account_info['password'],
                        $account_info['vendor'],
                        2,
                        $account_info['balance'],
                        $point
                    );
                } else {
                    //寫失敗記錄，然後暫時停止轉點
                    VendorRequestFail::create(['vendor_id' => $vendor_id]);
                    return [
                        'code' => 90112,
                        'message' => __('error_message.9011')
                    ];
                }
            } else {
                Log::error("無法獲取轉帳所需資訊", [
                    "vendor_id" => $vendor_id,
                    "member_id" => $member_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("VendorController::vendorBalanceToPlatform " . $e->getMessage(), [
                "vendor_id" => $vendor_id,
                "member_id" => $member_id,
                "point" => $point,
            ]);
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    // 檢查遊戲商最小可轉額度以及現在是否關閉轉點
    private function canVendorPointTransfer($vendor_id, $point)
    {
        if ($vendor_id == 4 && $point < 0.1) { // 豪龍小於 0.1 不轉
            return false;
        } elseif ($point < 0.01) {
            // 其他小於 0.01 不轉
            return false;
        }
        return true;
    }

    private function incrementFiveMinuteCount($memberId)
    {
        $key = array(
            'SingleWallet',
            $memberId,
            'api_count'
        );
        //用來判斷5分鐘內有沒有打超過3次
        $redis = new REDIS();
        if (!$redis->exists($key)) {
            $redis->incr($key);
            $redis->expire($key, 300);
        } else {
            $redis->incr($key);
        }
        $count = $redis->get($key);
        Log::info($memberId . "登入了 " . $count . " 次");
        return $count;
    }

    public function getSingleWalletBalanceNew(Request $request)
    {
        $memberId = $request->ID;
        $vendorId = intval($request->vendor_id);
        //查詢會員
        $member = DB::table('members as m')
            ->join('wallet_types as wt', function ($join) {
                $join->on('wt.id', 'm.wallet_type_id')
                    ->where('wt.status', 1);
            })
            ->join('member_wallets as mw', function ($join) use ($memberId) {
                $join->on('mw.wallet_type_id', 'm.wallet_type_id')
                    ->where('mw.member_id', $memberId)
                    ->where('mw.status', 1);
            })
            ->join('agent_wallets as aw', function ($join) use ($request) {
                $request->on('s')
            })
            ->where('m.id', $memberId)
            ->select(
                'm.*',
                'mw.balance as wallet_balance'
            )
            ->first();
        if (!is_object($member)) {
            return [
                'code' => 1005,
                'message' => __('error_message.1005')
            ];
        }
        //將各錢包餘額取代成玩家餘額
        $member->balance = $member->wallet_balance;

        $vendors = $this->vendors($member->id, null, null, $member->event_mode);

        $vendor_ids = array();
        if (is_array($vendors) && $vendors['code'] == 1000) {
            foreach ($vendors['data'] as $row) {
                if ($row->status == 1) {
                    $vendor_ids[] = $row->id;
                }
            }
        }
        // 沒有轉點記錄或最後一筆是轉出
        if (isset($vendorId) && $vendorId > 0) {
            //平台餘額轉入遊戲商
            if (!$this->canVendorPointTransfer($vendorId, $member->balance)) {
                //額度不足, 查詢遊戲商餘額
                $result = $this->getBalanceByVendorId($member->id, $vendorId, 0, );
                Log::debug("無法轉進遊戲商-用戶餘額不足", $result);
                return $result;
            }
            if ($member->balance > 0) {
                $result = $this->vendorTransfer($vendorId, $member->id, $member->balance, 1);
            } else {
                $result = [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $member->balance
                ];
            }
        } else {
            //查詢平台餘額
            $result = [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $member->balance
            ];
        }
        return $result;
    }

    /**
     * transferFromLastLog
     * - 依transaction_log的最後一筆資料將錢轉回錢包
     *
     * @bodyParam $memberId interger 會員編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "0.00"
     * }
     *
     * @response {
     *  "code": 99961,
     *  "message": "vendor回應錯誤",
     *  "data": "只有平台或單一遊戲商餘額，EX:0.00"
     * }
     */

    public function transferFromLastLog(Request $request)
    {
        $member = Member::find($request->ID);
        if (is_object($member)) {
            $result = [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $member->balance
            ];
        } else {
            $result = [
                'code' => 1007,
                'message' => __('error_message.1007')
            ];
        }

        $vendors = $this->vendors($member->id, null, null, $member->event_mode);
        $vendor_ids = array();
        if (is_array($vendors) && $vendors['code'] == 1000) {
            foreach ($vendors['data'] as $row) {
                if ($row->status == 1) {
                    $vendor_ids[] = $row->id;
                }
            }
        }

        //查詢最後一筆轉帳紀錄
        $last_log = $this->lastTransactionVendor($request->ID);
        //如果最後一筆是轉入紀錄的話要把錢轉回主錢包
        if ($last_log['vendor_id'] > 0 && in_array($last_log['vendor_id'], $vendor_ids)) {
            $vendor = Vendor::where('id', $last_log['vendor_id'])->first();
            if (is_object($vendor) && $vendor->status == 1) {
                //先取遊戲商錢包餘額
                $vendor_balance = $this->getBalanceByVendorId($request->ID, $last_log['vendor_id'], 1, );
                if (is_array($vendor_balance) && $vendor_balance['code'] == 1000) {
                    //餘額大對0才需要轉回平台
                    //豪龍需0.1才能轉帳
                    if ($this->canVendorPointTransfer($last_log['vendor_id'], $vendor_balance['data'])) {
                        //把錢轉回主錢包
                        $transfer_result = $this->vendorTransfer($last_log['vendor_id'], $request->ID, $vendor_balance['data'], 2);
                        if (is_array($transfer_result) && $transfer_result['code'] == 1000) {
                            //輸入成功回傳遊戲商餘額
                            $result = [
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => str_replace(',', '', $transfer_result['data']['balance'])
                            ];
                        } else {
                            //轉入失敗只能回傳平台餘額
                            if (is_array($transfer_result) && $transfer_result['code'] == 1435) {
                                $result = [
                                    'code' => 1435,
                                    'message' => __('error_message.1435'),
                                    'data' => $member->balance
                                ];
                            } else {
                                $result = [
                                    'code' => 99961,
                                    'message' => __('error_message.9996'),
                                    'data' => $member->balance
                                ];
                            }
                        }
                    } else {
                        Log::debug("無法轉進遊戲商-用戶餘額不足", $vendor_balance);
                    }
                } else {
                    //取錢包餘額失敗
                    Common::log($request->ID, $last_log['vendor_id'] . '查詢餘額失敗', 'check_vendor_balance');
                    $result = [
                        'code' => 99961,
                        'message' => __('error_message.9996'),
                        'data' => $member->balance
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * 轉點
     * @param $memberId int 玩家ID
     * @param $vendorId int 遊戲商ID
     * @param $username string 三方帳號
     * @param $password string 三方密碼
     * @param $vendor object 代理資訊
     * @param $type int 轉帳類型; 1:轉出至遊戲商, 2:轉入至平台
     * @param $beforeBalance float 轉點前玩家餘額
     * @param $point float 轉點點數
     * @return array
     */
    private function balanceTransaction($memberId, $vendorId, $username, $password, $vendor, $type, $beforeBalance, $point)
    {
        try {
            Log::debug("VendorController-2870 begin balanceTransaction", [
                    'vendor_id' => $vendorId,
                    'member_id' => $memberId,
                    'beforeBalance' => $beforeBalance,
                    'point' => $point,
                    'type' => $type]);

            //轉點前先取餘額，沒取到就暫時關閉此遊戲商轉點
            $vendor_balance = $vendor->getBalance($username, $password);
            if ($vendor_balance['code'] == 1000) {
                //幣值轉換

                $rate = floatval($vendor->getRate());
                if ($type == 1) {
                    //取得最大轉點金額
                    $orig_point = $point;
                    $point = $vendor->getTransferMinAmount($point);
                    //檢查是否有限制代理轉帳
                    if ($this->hasAgentLimit($memberId)) {
                        $limit = $this->getLimitByMemberId($memberId);
                        if (is_object($limit)) {
                            //扣款後小於0的也退回
                            if ($limit->limit - $point < 0) {
                                return [
                                    'code' => 1434,
                                    'message' => __('error_message.1434')
                                ];
                            }
                        }
                    }
                    $vendor_point = floor(strval($point * 10000 * $rate)) / 10000; //轉換比例後保留小數四位
                    if ($point == 0) {
                        $data = array(
                            'balance' => ($orig_point - $point),
                            'vendor_balance' => $vendor_balance['data']
                        );
                        return [
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $data
                        ];
                    }
                } else {
                    $vendor_point = $point;
                    $point = floor(strval($point * 10000 / $rate)) / 10000; //轉換比例後保留小數四位
                    if ($vendor->getTransferMinAmount($point) == 0) { // 如果不足最小轉帳金額就不轉
                        $member = Member::find($memberId);
                        $data = array(
                            'balance' => $member->balance,
                            'vendor_balance' => $vendor_balance['data']
                        );
                        return [
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $data
                        ];
                    } else if ($vendorId == 44 && $point < floor(strval(10000 / $rate)) / 10000) {
                        //issues_892 如果轉出小於1就不轉
                        $member = Member::find($memberId);
                        $data = array(
                            'balance' => $member->balance,
                            'vendor_balance' => $vendor_balance['data']
                        );
                        return [
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $data
                        ];
                    }

                }
                // 寫入轉點LOG
                $log = array();
                $log['agent_id'] = 0;
                $log['member_id'] = $memberId;
                $log['vendor_id'] = $vendorId;
                $log['type'] = $type;
                $log['point'] = $point;
                $log['vendor_point'] = 0;
                $log['rate'] = $rate;
                $log['before_balance'] = $beforeBalance;
                $log['balance'] = 0;
                $log['vendor_balance'] = 0;
                $log['status'] = 0;
                $log['remark'] = '';
                $log['admin'] = $memberId;
                // 寫入轉點歷程
                $transaction_log = app('App\Http\Controllers\TransactionLogController')
                    ->insertLog($log);

                //bill_no 用 transaction_id 代替, 如果之后有需要再用客制单号来代替
                $bill_no = strval($transaction_log->id);

                # issue 254, 錢包為負值時, 發TG通知
                if ($type == 1 && $vendorId == 4 && $vendor_balance['data'] < 0) {
                    $member = DB::table('haolong_accounts as ha')
                        ->join('members as m', 'm.id', 'ha.member_id')
                        ->where('ha.username', $username)
                        ->select(
                            'm.username',
                            'ha.username_modified'
                        )
                        ->first();
                    $tg_username = (is_object($member)) ? $member->username : "";
                    $tg_account = (is_object($member)) ? $member->username_modified : "";
                    $message = "[系統通知 玩家錢包為負值]" . PHP_EOL .
                        "Username: " . $tg_username . PHP_EOL .
                        "Hanlong Account: " . $tg_account . PHP_EOL .
                        "Balance: " . $vendor_balance['data'] . PHP_EOL .
                        "TransactionLogNo: " . $bill_no;
                    $this->tgService->sendMessage($message, 'warn');
                }

                $data = array(
                    'balance' => 0,
                    'vendor_balance' => 0
                );
                // 取遊戲點數及平台點數的比例
                if ($row = Vendor::find($vendorId)) {
                    //$rate = $row['rate'];
                    // type 1 轉入Vendor， 2 轉出Vendor
                    if ($type == 1) {
                        DB::beginTransaction();
                        Log::debug("VendorController-2987 transfer platform to vendor", [
                            'vendor_id' => $vendorId,
                            'member_id' => $memberId,
                            'vendor_point' => $vendor_point,
                            'point' => $point,
                            'type' => $type ]);

                        // 確認平台餘額
                        if ($member = Member::lockForUpdate()->find($memberId)) {
                            if ($member->balance >= $point) {
                                // 扣除平台餘額
                                $data['balance'] = ($member->balance - $point);

                                //更新代理限額
                                $limit = $this->getLimitByMemberId($memberId);
                                if (is_object($limit)) {
                                    $updateLimit = array();
                                    $lockLimit = TransferLimit::lockForUpdate()->find($limit->id);
                                    //先檢查是否有代理限額
                                    if ($this->hasAgentLimit($memberId)) {
                                        //如果扣完金額會小於0，就rollback回去
                                        if (($lockLimit['limit'] - $point) < 0) {
                                            DB::rollback();
                                            return [
                                                'code' => 1434,
                                                'message' => __('error_message.1434')
                                            ];
                                        }
                                    }
                                    $updateLimit['limit'] = $lockLimit['limit'] - $point;
                                    $lockLimit->update($updateLimit);
                                }

                                if ($member->update($data)) {
                                    DB::commit();
                                    //確認餘額大於0
                                    $after_balance = Member::find($memberId);
                                    if ($after_balance->balance >= 0) {
                                        // 加入Vendor餘額
                                        $rs = $vendor->transafer($username, $password, $type, $vendor_point, $bill_no);
                                        Log::debug("VendorController-2925 vendor transfer", [ 'rs' => $rs ]);
                                        if ($rs === true) {
                                            //取三方餘額
                                            $balance = $vendor->getBalance($username, $password);
                                            if ($balance['code'] == 1000) {
                                                $data['vendor_balance'] = $balance['data'];
                                            } else {
                                                $data['vendor_balance'] = null;
                                            }
                                            $result = [
                                                'code' => 1000,
                                                'message' => __('error_message.1000'),
                                                'data' => $data
                                            ];
                                        } else {
                                            // 轉點失敗後將平台餘額加回玩家身上
                                            if ($rs != '') {
                                                Log::debug("VendorController-2942 response fail", [ 'rs' => $rs ]);
                                                DB::beginTransaction();
                                                $member = Member::lockForUpdate()->find($memberId);
                                                $update = array(
                                                    'balance' => ($member->balance + $point)
                                                );
                                                $data['balance'] = $update['balance'];

                                                //更新代理限額
                                                $limit = $this->getLimitByMemberId($memberId);
                                                if (is_object($limit)) {
                                                    $updateLimit = array();
                                                    $lockLimit = TransferLimit::lockForUpdate()->find($limit->id);
                                                    $updateLimit['limit'] = $lockLimit['limit'] + $point;
                                                    $lockLimit->update($updateLimit);
                                                }

                                                if ($member->update($update)) {
                                                    if(is_array($rs) && $rs['code'] == 1435) {
                                                        $result = $rs;
                                                    } else {
                                                        $result = [
                                                            'code' => 9996,
                                                            'message' => $rs
                                                        ];
                                                    }
                                                } else {
                                                    $result = [
                                                        'code' => 2004,
                                                        'message' => $rs
                                                    ];
                                                }
                                                DB::commit();
                                            } else {
                                                //遊戲商無回應不還錢，因為不確定是否有成功
                                                $result = [
                                                    'code' => 9995,
                                                    'message' => __('error_message.9995'),
                                                ];
                                            }
                                        }
                                    } else {
                                        //餘額不足
                                        $result = [
                                            'code' => 1022,
                                            'message' => __('error_message.1022')
                                        ];
                                    }
                                } else {
                                    //數據庫錯誤，請稍後再試
                                    $result = [
                                        'code' => 2001,
                                        'message' => __('error_message.2001')
                                    ];
                                }
                            } else {
                                //餘額不足
                                $result = [
                                    'code' => 1022,
                                    'message' => __('error_message.1022')
                                ];
                            }
                        } else {
                            $result = [
                                'code' => 1005,
                                'message' => __('error_message.1005')
                            ];
                        }
                    } else {
                        DB::beginTransaction();
                        // 先確認有會員已存在
                        if ($member = Member::lockForUpdate()->find($memberId)) {
                            // 確認Vendor餘額(2020-02-25改為轉點開始前確認)
                            // $vendor_balance = $vendor->getBalance($username, $password);
                            if ($vendor_balance['code'] == 1000 && isset($vendor_balance['data'])) {
                                $vendor_balance['data'] = str_replace(',', '', $vendor_balance['data']);
                                Log::debug(
                                    "VendorController-1711 transfer vendor to platform",
                                    [
                                        'vendor_id' => $vendorId,
                                        'member_id' => $memberId,
                                        'vendor_point' => $vendor_point,
                                        'point' => $point,
                                        'type' => $type
                                    ]
                                );
                                if ($vendor_balance['data'] >= $vendor_point && $vendor_balance['code'] == 1000) {
                                    // 扣除Vendor餘額
                                    $rs = $vendor->transafer($username, $password, $type, $vendor_point, $bill_no);
                                    if ($rs === true) {
                                        // 加入平台餘額
                                        $data['balance'] = ($member->balance + $point);
                                        if ($member->update($data)) {
                                            $data['vendor_balance'] = floor(strval($vendor_balance['data'] * 10000 / $rate)) / 10000 - $point;

                                            //更新代理限額
                                            $limit = $this->getLimitByMemberId($memberId);
                                            if (is_object($limit)) {
                                                $updateLimit = array();
                                                $lockLimit = TransferLimit::lockForUpdate()->find($limit->id);
                                                $updateLimit['limit'] = $lockLimit['limit'] + $point;
                                                $lockLimit->update($updateLimit);
                                            }

                                            $result = [
                                                'code' => 1000,
                                                'message' => __('error_message.1000'),
                                                'data' => $data
                                            ];
                                        } else {
                                            //餘額更新失敗
                                            $result = [
                                                'code' => 2001,
                                                'message' => __('error_message.2001')
                                            ];
                                        }
                                    } else {
                                        if ($rs != '') {
                                            //遊戲回應轉點失敗
                                            if(is_array($rs) && $rs['code'] == 1435) {
                                                $result = $rs;
                                            } else {
                                                $result = [
                                                    'code' => 9996,
                                                    'message' => $rs
                                                ];
                                            }
                                        } else {
                                            //遊戲商無回應不還錢，因為不確定是否有成功
                                            $result = [
                                                'code' => 9995,
                                                'message' => __('error_message.9995'),
                                            ];
                                        }
                                    }
                                } else {
                                    //遊戲商餘額不足
                                    $result = [
                                        'code' => 1022,
                                        'message' => __('error_message.1022')
                                    ];
                                    $data['vendor_balance'] = $vendor_balance['data'];
                                }
                            } else {
                                //取不到遊戲商餘額
                                $result = [
                                    'code' => 10221,
                                    'message' => __('error_message.1022')
                                ];
                                $data['vendor_balance'] = 0;
                            }
                        } else {
                            //會員不存在
                            $result = [
                                'code' => 1005,
                                'message' => __('error_message.1005')
                            ];
                        }
                    }

                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
                    ));
                    // 更新轉點記錄
                    $log = array();
                    $log['agent_id'] = $member->parent;
                    $log['vendor_point'] = $vendor_point;
                    $log['rate'] = $rate;
                    $log['balance'] = $data['balance'];
                    $log['vendor_balance'] = $data['vendor_balance'];
                    $log['status'] = $result['code'];
                    $log['result'] = json_encode($data);
                    $log['checksum'] = app('App\Http\Controllers\TransactionLogController')->getTransactionLogChecksum($memberId, $transaction_log->transaction_no, $vendorId, $type, $point, $log['balance'], $log['status'], $log['result'], '');
                    $update_result = $transaction_log->update($log);

                    if ($result['code'] == 1000) {
                        DB::commit();
                    } else {
                        DB::rollback();
                    }

                    // 餘額格式化
                    if ($result['code'] == 1000) {
                        if (is_numeric($data['balance'])) {
                            $data['balance'] = number_format($data['balance'], 4);
                        }
                        if (is_numeric($data['vendor_balance'])) {
                            $data['vendor_balance'] = number_format($data['vendor_balance'], 4);
                        }
                        $result['data'] = $data;
                    }
                    return $result;
                } else {
                    return [
                        'code' => 1407,
                        'message' => __('error_message.1407')
                    ];
                }
            } else {

                Log::debug(
                    'VendorController-3324 begin VendorRequestFail step 1',
                    [
                        'vendor_id' => $vendorId,
                        'member_id' => $memberId
                    ]
                );
                //找出總代ID
                $general_agent = DB::table('members as m')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->where('m.id', $memberId)
                    ->select(
                        DB::raw("REPLACE(SUBSTRING_INDEX(hierarchy_path, '/', 3), '/1/', '') as agent_id")
                    )
                    ->first();

                //寫失敗記錄，然後暫時停止轉點
                VendorRequestFail::create(
                    [
                        'vendor_id' => $vendorId,
                        'general_agent_id' => $general_agent->agent_id
                    ]
                );

                Log::debug(
                    'VendorController-3324 begin VendorRequestFail step 2',
                    [
                        'vendor_id' => $vendorId,
                        'member_id' => $memberId,
                        'general_agent' => json_encode($general_agent)
                    ]
                );

                return [
                    'code' => 90112,
                    'message' => __('error_message.9011')
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            DB::rollback();
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function hasAgentLimit($memberId)
    {
        $result = DB::table('agent_customized_settings as acs')
            ->join('agent_groups as ag', 'ag.id', 'acs.agent_group_id')
            ->join('agents as a', 'a.organization_id', 'ag.organization_id')
            ->join('members as m', function ($join) use ($memberId) {
                $join->on('m.parent', 'a.id')
                    ->where('m.id', $memberId);
            })
            ->where('acs.setting_key', 6200)
            ->select('acs.value as value')
            ->first();
        return $result == null ? 0 : $result->value;
    }

    private function getLimitByMemberId($memberId)
    {
        $result = DB::table('transfer_limits as tl')
            ->join('agent_groups as ag', 'ag.id', 'tl.agent_group_id')
            ->join('agents as a', 'a.organization_id', 'ag.organization_id')
            ->join('members as m', function ($join) use ($memberId) {
                $join->on('m.parent', 'a.id')
                    ->where('m.id', $memberId);
            })
            ->select('tl.*')
            ->first();
        return $result;
    }

    private function getBillNo()
    {
        $number = date('mHis') . rand(10, 99); //交易編號
        $is_exist = TransactionLog::where('remark', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getBillNo();
        } else {
            return $number;
        }
    }

    /**
     * 取Vendor帳號 & info
     * @param $memberId int
     * @param $vendorId int
     * @param $show_disable int 遊戲商顯示停用
     * @return array
    */
    private function getVendorAccount($memberId, $vendorId, $show_disable = 0)
    {
        //查詢這個會員可以玩的遊戲商
        $vendors = $this->vendorList($memberId, 'v.id', 0, $show_disable);
        //如果遊戲商編號不在可玩遊戲商中就不讓他玩
        if (in_array($vendorId, $vendors)) {
            switch ($vendorId) {
                    // AG
                case 1:
                    $vendor = new AgAccount();
                    break;
                    // Joker
                case 2:
                    $vendor = new JokerAccount();
                    break;
                    // SCR(918)
                case 3:
                    $vendor = new ScrAccount();
                    break;
                    // 豪龍
                case 4:
                    $vendor = new HaolongAccount();
                    break;
                    // GW99
                case 5:
                    $vendor = new GreatWallAccount();
                    break;
                    // ACE333
                case 6:
                    $vendor = new Ace333Account();
                    break;
                    // 鬥雞(沒有雞)
                    // case 7:
                    //     $vendor = new ChichenGameAccount();
                    //     break;
                    // Vplay
                case 8:
                    $vendor = new VplayAccount();
                    break;
                    // 鬥雞
                case 9:
                    $vendor = new CokeFightingAccount();
                    break;
                    //Mega888
                case 10:
                    $vendor = new Mega888Account();
                    break;
                    //Xe88
                case 11:
                    $vendor = new Xe88Account();
                    break;
                    //Lucky
                case 12:
                    $vendor = new LuckyAccount();
                    break;
                    //eBET
                case 13:
                    $vendor = new EbetAccount();
                    break;
                    //iSB
                case 14:
                    $vendor = new IsbAccount();
                    break;
                    //Ygg
                case 15:
                    $vendor = new YggAccount();
                    break;
                    //WM
                case 16:
                    $vendor = new WmAccount();
                    break;
                    //PT
                case 17:
                    $vendor = new PtAccount();
                    break;
                    //SBO
                case 18:
                    $vendor = new SboAccount();
                    break;
                    //MT
                case 19:
                    $vendor = new MtAccount();
                    break;
                    //Pg
                case 20:
                    $vendor = new PgAccount();
                    break;
                    //小艾
                case 21:
                    $vendor = new IaAccount();
                    break;
                    //Vpower
                case 22:
                    $vendor = new VpowerAccount();
                    break;
                    //Pussy888
                case 23:
                    $vendor = new Pussy888Account();
                    break;
                case 24:
                    $vendor = new PgsAccount();
                    break;
                    //AE Sexy
                case 25:
                    $vendor = new AesAccount();
                    break;
                    //IGS
                case 26:
                    $vendor = new IgsAccount();
                    break;
                case 27:
                    $vendor = new CtAccount();
                    break;
                case 28:
                    $vendor = new Sky1388Account();
                    break;
                case 29:
                    $vendor = new DgAccount();
                    break;
                case 30:
                    $vendor = new Play8oyAccount();
                    break;
                case 31:
                    $vendor = new SkybetAccount();
                    break;
                case 32:
                    $vendor = new RmAccount();
                    break;
                case 33:
                    $vendor = new N918Account();
                    break;
                case 34:
                    $vendor = new TerraAccount();
                    break;
                case 35:
                    $vendor = new AllbetAccount();
                    break;
                case 36:
                    $vendor = new VspokerAccount();
                    break;
                case 37:
                    $vendor = new SaAccount();
                    break;
                case 38:
                    $vendor = new EsportAccount();
                    break;
                case 39:
                    $vendor = new FishingAccount();
                    break;
                case 40:
                    $vendor = new Menang88Account();
                    break;
                case 41:
                    $vendor = new NptAccount();
                    break;
                case 42:
                    $vendor = new V8Account();
                    break;
                case 43:
                    $vendor = new Obet33Account();
                    break;
                case 44:
                    $vendor = new SabaAccount();
                    break;
                case 45:
                    $vendor = new Bola2Account();
                    break;
                case 46:
                    $vendor = new Plus100Account();
                    break;
                case 47:
                    $vendor = new SpadegamingAccount();
                    break;
                case 48:
                    $vendor = new NsAccount();
                    break;
                case 49:
                    $vendor = new BngAccount();
                    break;
                case 50:
                    $vendor = new PgsswAccount();
                    break;
                case 51:
                    $vendor = new MgcAccount();
                    break;
                case 52:
                    $vendor = new YbetAccount();
                    break;
                case 53:
                    $vendor = new VsportAccount();
                    break;
                    //Testgame
                case 99:
                    $vendor = new TestgameAccount();
                    break;
                default:
                    return  [[
                        'code' => 9994,
                        'message' => __('error_message.9994')
                    ], null];
                    break;
            }
            //簡單的方案是取遊戲帳號前先進行 lock
            $lock = new VendorAccountNamedLock($memberId, $vendorId);
            $info = $this->getVendorInfo($memberId, $vendorId);
            if (is_object($info)) {
                $vendor_info_id = $info->id;
            } else {
                $vendor_info_id = 0;
            }

            $account = $vendor->useWritePdo()
                ->where('vendor_info_id', $vendor_info_id)
                ->where('member_id', $memberId)
                ->orderBy('id', 'ASC')
                ->first();

            // 918轉移(狀態0:未取餘額，1:取得舊帳號餘額，2:轉移完成，3:剩餘點數回收完成)
            if ($vendorId == 3 && is_object($account) && $account->status < 2 && $account->vendor_info_id == 0) {
                $old_acccount = $account;
                $is_success = false;
                $scr_class = new VendorScr($info);
                if ($old_acccount->status == 0) {
                    //取舊帳號餘額
                    $get_balance = $scr_class->getBalanceOld($old_acccount->player_id);
                    if ($get_balance['code'] == 1000) {
                        $old_balance = $get_balance['data'];
                        $old_acccount->update(['status' => 1, 'balance' => $old_balance]);
                        $is_success = true;
                    } else {
                        //取餘額失敗, 帳號升級中
                        $lock->unLock();
                        return  [[
                            'code' => 9011,
                            'message' => __('error_message.9011')
                        ], null];
                    }
                }
                if ($old_acccount->status == 1 || $is_success) {
                    DB::beginTransaction();
                    if (!isset($old_balance)) {
                        $old_balance = $old_acccount->balance;
                    }
                    //取新帳號
                    if ($account = $vendor->lockForUpdate()->where('vendor_info_id', $vendor_info_id)->where('member_id', 0)->first()) {
                        //綁定新帳號
                        $account->update(['member_id' => $memberId]);
                        if (isset($old_balance) &&  $old_balance > 0) {
                            //將餘額存入新帳號
                            $deposit_result = $scr_class->transafer($account->player_id, '', 1, $old_balance, $this->getBillNo());
                            if (!$deposit_result) {
                                //轉入失敗就不要這個帳號了，避免有餘額重覆問題
                                $account->update(['member_id' => -999999999]);
                                $lock->unLock();
                                //存餘額失敗, 帳號升級中
                                return  [[
                                    'code' => 90111,
                                    'message' => __('error_message.9011')
                                ],  null];
                            } else {
                                //修改舊帳號
                                $old_acccount->update(['status' => 2, 'member_id' => -$memberId]);
                            }
                        } else {
                            //沒餘額的就直接修改舊帳號
                            $old_acccount->update(['status' => 3, 'member_id' => -$memberId]);
                        }
                    }
                    DB::commit();
                }
            }

            // 如果還沒綁定帳號就先綁定，綁定帳號要看他的AGENT是用哪組設定
            if (!is_object($account)) {
                DB::beginTransaction();
                $account = $vendor->lockForUpdate()
                    ->where('vendor_info_id', $vendor_info_id)
                    ->where('member_id', 0)
                    ->first();
                if ($account) {
                    $vendor->find($account->id)->update(array(
                        'member_id' => $memberId
                    ));
                }
                DB::commit();
            }

            if (!is_object($account)) {
                $account_log = json_encode($account);
                Log::error('aaaaaaaaaa' . $account_log, ['vendor_id' => $vendorId, 'member_id' => $memberId, 'info' => $info]);
            }
            $lock->unLock();
            if (is_object($account)) {
                return [$account, $info];
            } else {
                return  [[
                    'code' => 99931,
                    'message' => __('error_message.9993')
                ], null];
            }
        } else {
            return [[
                'code' => 99941,
                'message' => __('error_message.9994'),
                'data' => $vendors
            ], null];
        }
    }

    /**
     * 取轉帳所需資訊
     * @param $vendorId int 遊戲商ID
     * @param $memberId int 玩家ID
     * @param $type int 轉帳類型; 1:轉出至遊戲商, 2:轉入至平台
     * @return object
    */
    private function preTransaction($vendorId, $memberId, $type)
    {
        $show_disable = ($type == 2) ? 1: 0;
        list($account, $info) = $this->getVendorAccount($memberId, $vendorId, $show_disable);
        if (!is_object($account)) {
            return $account;
        } else {
            $password = [REDACTED], $account->created_at);
            switch ($vendorId) {
                case 1:
                    if (isset($account->password) && $account->password != '') {
                        $password = [REDACTED];
                    }
                    $vendor = new VendorAg($info);
                    $username = $account->username;
                    break;
                // Joker
                case 2:
                    $vendor = new VendorJoker($info);
                    $username = $account->username;
                    break;
                // SCR(918)
                case 3:
                    $vendor = new VendorScr($info);
                    $username = $account->player_id;
                    break;
                // 豪龍
                case 4:
                    $vendor = new VendorHaolong($info);
                    $username = $account->username;
                    break;
                // GW99
                case 5:
                    $vendor = new VendorGreatWall($info);
                    $username = $account->username;
                    break;
                // ACE333
                case 6:
                    $vendor = new VendorAce333($info);
                    $username = $account->player_id;
                    break;
                // 鬥雞(沒有雞)
                    // case 7:
                    //     $vendor = new VendorChichenGame();
                    //     $username = $account->username;
                    //     break;
                // Vplay
                case 8:
                    $vendor = new VendorVplay($info);
                    $username = $account->username;
                    break;
                // 鬥雞
                case 9:
                    $vendor = new VendorCokeFighting();
                    $username = $account->username;
                    break;
                // Mega888
                case 10:
                    $vendor = new VendorMega888($info);
                    $username = $account->player_id;
                    break;
                // Xe88
                case 11:
                    $vendor = new VendorXe88($info);
                    $username = $account->username;
                    break;
                // Lucky
                case 12:
                    $vendor = new VendorLucky($info);
                    $username = $account->player_id;
                    break;
                // eBET
                case 13:
                    $vendor = new VendorEbet($info);
                    $username = $account->username;
                    break;
                // iSB
                case 14:
                    $vendor = new VendorIsb($info);
                    $username = $account->username;
                    $password = [REDACTED]; //因為ISB轉帳需要PLAYER ID，但不需要密碼，所以用這個
                    break;
                // Ygg
                case 15:
                    $vendor = new VendorYgg($info);
                    $username = $account->username;
                    break;
                //WM
                case 16:
                    $vendor = new VendorWm($info);
                    $username = $account->user_id;
                    break;
                // PT
                case 17:
                    $vendor = new VendorPt($info);
                    $username = $account->username;
                    break;
                //SBO
                case 18:
                    $vendor = new VendorSbo($info);
                    $username = $account->username;
                    break;
                //MT
                case 19:
                    $vendor = new VendorMt($info);
                    $username = $account->username;
                    break;
                //Pg
                case 20:
                    $vendor = new VendorPg($info);
                    $username = $account->username;
                    break;
                //小艾
                case 21:
                    $vendor = new VendorIa($info);
                    $username = $account->username;
                    break;
                //Vpower
                case 22:
                   $vendor = new VendorVpower($info);
                    $username = $account->username;
                   break;
                //Pussy888
                case 23:
                    $vendor = new VendorPussy888($info);
                    $username = $account->player_id;
                    break;
                case 24:
                    $vendor = new VendorPgs($info);
                    $username = $account->username;
                    break;
                //AE Sexy
                case 25:
                    $vendor = new VendorAes($info);
                    $username = $account->username;
                    break;
                //IGS
                case 26:
                    $vendor = new VendorIgs($info);
                    $username = $account->player_id;
                    break;
                case 27:
                    $vendor = new VendorCt($info);
                    $username = $account->username;
                    break;
                case 28:
                    $vendor = new VendorSky1388($info);
                    $username = $account->username;
                    break;
                case 29:
                    $vendor = new VendorDg($info);
                    $username = $account->username;
                    break;
                case 30:
                    $vendor = new VendorPlay8oy($info);
                    $username = $account->username;
                    break;
                case 31:
                    $vendor = new VendorSkybet($info);
                    $username = $account->username;
                    break;
                case 32:
                    $vendor = new VendorRm($info);
                    $username = $account->username;
                    break;
                case 33:
                    $vendor = new VendorN918($info);
                    $username = $account->player_id;
                    break;
                case 34:
                    $vendor = new VendorTerra($info);
                    $username = $account->username;
                    break;
                case 35:
                    $vendor = new VendorAllbet($info);
                    $username = $account->username;
                    break;
                case 36:
                    $vendor = new VendorVSPoker($info);
                    $username = $account->username;
                    break;
                case 37:
                    $vendor = new VendorSa($info);
                    $username = $account->username;
                    break;
                case 38:
                    $vendor = new VendorEsport($info);
                    $username = $account->username;
                    break;
                case 39:
                    $vendor = new VendorFishing($info);
                    $username = $account->username;
                    break;
                case 40:
                    $vendor = new VendorMenang88($info);
                    $username = $account->player_id;
                    break;
                case 41:
                    $vendor = new VendorNpt($info);
                    $username = $account->username;
                    break;
                case 42:
                    $vendor = new VendorV8($info);
                    $username = $account->username;
                    break;
                case 43:
                    $vendor = new VendorObet33($info);
                    $username = $account->username;
                    break;
                case 44:
                    $vendor = new VendorSaba($info);
                    $username = $account->username_modified;
                    break;
                case 45:
                    $vendor = new VendorBola2($info);
                    $username = $account->username;
                    break;
                case 46:
                    $vendor = new VendorPlus100($info);
                    $username = $account->username;
                    break;
                case 47:
                    $vendor = new VendorSpadeGaming($info);
                    $username = $account->username;
                    break;
                case 48:
                    $vendor = new VendorNs($info);
                    $username = $account->username;
                    break;
                case 49:
                    $vendor = new VendorBng($info);
                    $username = $account->username;
                    break;
                case 51:
                    $vendor = new VendorMgc($info);
                    $username = $account->username;
                    break;
                case 52:
                    $vendor = new VendorYbet($info);
                    $username = $account->username;
                    break;
                case 53:
                    $vendor = new VendorVsport($info);
                    $username = $account->username;
                    break;
                //Testgame
                case 99:
                    $vendor = new VendorTestgame($info);
                    $username = $account->player_id;
                    break;
            }
            $member = Member::where('id', $memberId)
                ->select('id', 'balance')
                ->first();
            return [
                'vendor' => $vendor,
                'username' => $username,
                'balance' => $member->balance,
                'password' => $password
            ];
        }
    }

    private function getVendorPassword($username, $createTime)
    {
        return substr(md5($username . strtotime($createTime)), 8, 12);
    }

    private function getVendorInfo($memberId, $vendorId)
    {
        $info = null;
        // 查代理資訊
        $agent = DB::table('members as m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('m.id', $memberId)
            ->select(
                'a.hierarchy',
                'm.wallet_type_id'
            )
            ->first();
        if (is_object($agent)) {
            // 查代理使用的設定檔
            $array = explode(',', $agent->hierarchy);
            //頭尾兩個會是無效的
            for ($i = 1; $i < (count($array) - 1); $i++) {
                $agent_vendor = DB::table('agent_vendors as av')
                    ->join('vendor_infos as vi', function ($join) use ($agent) {
                        $join->on('vi.id', 'av.vendor_info_id')
                            ->where('vi.wallet_type_id', $agent->wallet_type_id)
                            ->where('vi.status', 1);
                    })
                    ->where('av.vendor_id', $vendorId)
                    ->where('av.status', 1)
                    ->select('av.*')
                    ->first();
                if (is_object($agent_vendor)) {
                    $info_id = $agent_vendor->vendor_info_id;
                    $info = VendorInfo::find($info_id);
                    break;
                }
            }
            if (is_object($info)) {
                $decrypt = Common::parseVendorInfo($info);
                $collection = collect($info);
                $merged = $collection->merge((array)$decrypt);
                return (object)$merged->all();
            }
        }
    }

    // 查詢3個月內有無登入紀錄
    private function memberLoginLog($memberId)
    {
        return MemberLoginLog::where('member_id', $memberId)
            ->whereRaw('created_at >= DATE_SUB(now(), INTERVAL 3 MONTH)')
            ->skip(1)
            ->first();
    }

    //判斷轉出結果
    private function getVendorTransferOutResult ($transfer_result, $balance)
    {
        $code = 9996;

        if (is_array($transfer_result)) {
            $code = isset($transfer_result['code']) ? $transfer_result['code'] : $code;
            $balance = ($code == 1000) ? $transfer_result['data']['vendor_balance'] : $balance;
        }

        return [
            'code' => $code,
            'message' => __('error_message.' . $code),
            'data' => $balance
        ];
    }

    private function rule()
    {
        return [
            'vendor_id' => 'required|digits_between:0,10',
            'point' => array(
                'required',
                'regex:/(^[1-9][0-9]*$)/u'
            )
        ];
    }

    private function messages()
    {
        return [
            'new_password.required' => __('error_message.1019'),
            'new_password.r_alpha_num' => __('error_message.2034'),
            'new_password.between' => __('error_message.1020'),

            'game_id.required' => __('error_message.1414'),
            'game_id.digits_between' => __('error_message.1414'),

            'vendor_id.required' => __('error_message.1415'),
            'vendor_id.digits_between' => __('error_message.1416'),

            'game_type.required' => __('error_message.1404'),

            'start_time.date_format' => __('error_message.1311'),
            'end_time.date_format' => __('error_message.1312'),

            'point.required' => __('error_message.2026'),
            'point.regex' => __('error_message.2026'),

            'status.in' => __('error_message.2033'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}