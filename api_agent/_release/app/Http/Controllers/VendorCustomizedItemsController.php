<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use Illuminate\Http\Request;
use \DB;
use \Exception;
use Validator;
class VendorCustomizedItemsController extends Controller
{
    //
    private $saveRules = [
        'group_id'  => 'required|integer|min:1',
        'item_type' => 'required|integer|min:1|max:4',
        'status'    => 'required|integer|min:0|max:2',
        'vendor_id' => 'required_if:item_type,1,2,3',
        'game_id' => 'required_if:item_type,2',
        'type_id' => 'required_if:item_type,3',
        'path' => 'required_if:item_type,4',
    ];

    public function getGamesByVendorId(Request $request)
    {
        $vendor_id = $request->vendor_id;
        $agent_game_lists = $this->agentGameList($request->HOST, $vendor_id);
        $games = DB::table('games as g')
            ->leftJoin('game_names as gn', function ($join) use ($request) {
                $join->on('gn.game_id', 'g.id')
                    ->where('gn.language_id', $request->LANGUAGE);
            })
            ->where('g.vendor_id', $vendor_id)
            ->where('g.status', 1)
            ->whereIn('g.id', $agent_game_lists)
            ->select(
                'g.id',
                DB::raw('IFNULL(gn.name, g.name) as name')
            )
            ->get();
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'games' => $games
        ]);
    }

    public function saveItem(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), $this->saveRules);
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                ]);
            }
            //如果item_type是2(開啟遊戲),遊戲類別就直接抓games的類別
            if($request->item_type == 2){
                $game = DB::table('games')->find($request->game_id);
                $type_id = $game->type_id;
            } else {
                $type_id = ($request->type_id)?$request->type_id:0;
            }

            \Log::info('saveItem', $request->all());
            $group_id = $request->group_id;
            $item_type = $request->item_type;
            $status = $request->status;
            $name = ($request->name)?$request->name:null;
            $vendor_id = ($request->vendor_id)?$request->vendor_id:0;
            $game_id = ($request->game_id)?$request->game_id:0;
            $path = ($request->path)?$request->path:'';
            $is_new = ($request->is_new == 'on')?1:0;
            $is_hot = ($request->is_hot == 'on')?1:0;
            $image = null;
            if (isset($request->vendor_image) && $request->vendor_image != '') {
                // 移動圖檔至本地及上傳到GCP
                $uploadResult = $this->uploadBase64File($request->vendor_image);
                if ($uploadResult['code'] != 1000) {
                    return response()->json([
                        'code' => $uploadResult['code'],
                        'message' => $uploadResult['message'],
                    ]);
                }
                $image = $uploadResult['message'];
            }

            $item = new VendorCustomizedItem();
            if($request->id) {
                $item = VendorCustomizedItem::find($request->id);
            } else {
                // find the max sorts of agent_group_id
                $max_sorts = VendorCustomizedItem::where('agent_group_id', $group_id)->max('sorts');
                $item->sorts = $max_sorts + 1;
            }
            $item->agent_group_id = $group_id;
            $item->item_type = $item_type;
            $item->vendor_id = $vendor_id;
            $item->status = $status;
            $item->name = $name;
            $item->type_id = $type_id;
            $item->game_id = $game_id;
            $item->path = $path;
            $item->is_new = $is_new;
            $item->is_hot = $is_hot;
            if($image) {
                $item->image = $image;
            }
            $item->save();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function removeItem(Request $request)
    {
        try {
            VendorCustomizedItem::find($request->id)->delete();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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

    public function getItem(Request $request)
    {
        try {
            $item = VendorCustomizedItem::find($request->id);
            $vendor = Vendor::find($item->vendor_id);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => array(
                    'item' => $item,
                    'vendor' => $vendor
                )
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

    public function list(Request $request)
    {
        try {
            $group_id = $request->group_id;
            $group = DB::table('agent_groups')->find($group_id);

            //確認是否有開啟這個組別的權限
            $checkAgentGroupPermission = $this->checkAgentGroupPermission($request->group_id, $request->ID);
            //Admin跟Admin的子帳號不檢查
            if($request->HOST != 1 && !$checkAgentGroupPermission) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            if ($group) {
                $vendors = DB::table('vendors')->where('status', 1)->select('id', 'name')->get();
                $game_types = DB::table('game_types')->where('status', 1)->select('id', 'name')->get();
                $groups = DB::table('agent_groups')->where('agent_group_type', 5)->get();
                $agent_game_lists = $this->agentGameList($request->HOST);
                // 取出首頁客製入口
                $model = DB::table('vendor_customized_items as vci')
                    ->leftJoin('vendors as v', 'v.id', 'vci.vendor_id')
                    ->where('vci.agent_group_id', $group_id)
                    ->where(function ($query) use ($agent_game_lists) {
                        $query->where('vci.game_id', 0)
                            ->orWhereIn('vci.game_id', $agent_game_lists);
                    })
                    ->select(
                        'vci.id',
                        'vci.item_type',
                        'vci.vendor_id',
                        'vci.game_id',
                        'vci.type_id',
                        'vci.path',
                        DB::raw('IFNULL(vci.name, v.name) as name'),
                        DB::raw('IFNULL(vci.image, v.image) as image'),
                        DB::raw('IFNULL(vci.app_icon, v.app_icon) as app_icon'),
                        DB::raw('IFNULL(vci.is_new, v.is_new) as is_new'),
                        DB::raw('IFNULL(vci.is_hot, v.is_hot) as is_hot'),
                        'vci.status'
                    )
                    ->groupBy('vci.item_type', 'vci.vendor_id', 'vci.game_id', 'vci.type_id', 'vci.path')
                    ->orderByDesc('vci.sorts');
                $data = $model->get();

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => array(
                        'vendors' => $vendors,
                        'game_types' => $game_types,
                        'group' => $group,
                        'groups' => $groups,
                        'items' => $data
                    )
                ]);
            } else {
                throw new Exception("group_id not found", 0);
            }


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

    public function updateSorts(Request $request)
    {
        try {
            $ids = $request->ids;
            $id_array = explode(",", $ids);
            $id_array = array_reverse($id_array);
            foreach($id_array as $key=>$id) {
                $item = VendorCustomizedItem::find($id);
                if($item) {
                    $item->update(["sorts"=>$key]);
                }
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
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function checkAgentGroupPermission($agent_group_id, $agent_id)
    {
        $result = false;
        $agent_group_permissions = DB::table('agent_group_permissions')
            ->where('agent_id', $agent_id)
            ->where('agent_group_id', $agent_group_id)
            ->first();
        if(is_object($agent_group_permissions)) {
            $result = true;
        }

        return $result;
    }

}
