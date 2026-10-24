<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use App\Models\Member;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\AgentGame;
use DB;
use App\REDIS;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function getIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['HTTP_VIA'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    public function getPagination($total, $prePage, $page)
    {
        $pagination = array();
        $pagination['per_page'] = $prePage;
        $pagination['total'] = $total;
        $pages = ceil($total / $prePage);
        $pagination['pages'] = $pages;
        $pagination['previous'] = ($page != 1 && $total > 0) ? ($page - 1) : '';
        $pagination['current'] = $page;
        $pagination['next'] = ($pages > $page) ? ($page + 1) : '';
        return $pagination;
    }

    public function uploadPhoto($file, $is_public = true)
    {
        try {
            // 移動圖檔至本地
            $destinationPath = base_path() . '/storage/uploads/';
            $fileName = $file->getClientOriginalName();
            $fileName = (date('U') . rand(1, 100)) . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
            $path = $destinationPath . $fileName;
            $file->move($destinationPath, $path);
            // 上傳圖檔到GOOGLE CLOUD STORAGE
            $storageClient = new StorageClient([
                'projectId' => env('GOOGLE_CLOUD_PROJECT_ID', 'your-project-id'),
                'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE', null)
            ]);
            $bucket = $storageClient->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET', 'your-bucket-name'));
            $adapter = new GoogleStorageAdapter($storageClient, $bucket);
            $filesystem = new Filesystem($adapter);
            // 公開的直接存網址，非公開的存檔名需要檢視時再取
            if ($is_public) {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'public'
                ));
                $fileName = $adapter->getUrl($fileName);
            } else {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'private'
                ));
            }
            return array(
                'code' => 1000,
                'message' => $fileName
            );
        } catch (\Exception $e) {
            return array(
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            );
        }
    }

    public function uploadBase64File($file, $is_public = true)
    {
        try {
            $file = str_replace(' ', '+', $file);
            // 在本地寫入檔案
            $destinationPath = base_path() . '/storage/uploads/';
            $pos = strpos($file, ';');
            $type = explode(':', substr($file, 0, $pos))[1];
            $mime = explode('/', $type);

            $fileName = (date('U') . rand(1, 100)) . '.' . $mime[1];
            $path = $destinationPath . $fileName;
            $file = substr($file, strpos($file, ',') + 1, strlen($file));
            $dataBase64 = base64_decode($file);
            file_put_contents($path, $dataBase64);

            // 上傳圖檔到GOOGLE CLOUD STORAGE
            $storageClient = new StorageClient([
                'projectId' => env('GOOGLE_CLOUD_PROJECT_ID', 'your-project-id'),
                'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE', null)
            ]);
            $bucket = $storageClient->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET', 'your-bucket-name'));
            $adapter = new GoogleStorageAdapter($storageClient, $bucket);
            $filesystem = new Filesystem($adapter);
            // 公開的直接存網址，非公開的存檔名需要檢視時再取
            if ($is_public) {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'public'
                ));
                $fileName = $adapter->getUrl($fileName);
            } else {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'private'
                ));
            }
            return array(
                'code' => 1000,
                'message' => $fileName
            );
        } catch (\Exception $e) {
            return array(
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            );
        }
    }

    public function log($user, $content, $action)
    {
        if (is_array($content)) {
            unset($content['password']);
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        DB::table('access_log_frontend_' . date('Ym'))->insert(array(
            'user' => $user,
            'content' => $content,
            'action' => $action,
            'ip' => $this->getIp()
        ));
    }

    public function listChild($parentId)
    {
        static $result = array();
        $result[] = $parentId;
        $rows = Agent::where('parent', $parentId)->get();
        foreach ($rows as $row) {
            $this->listChild($row['id']);
        }
        return $result;
    }

    public function listParent($parentId)
    {
        static $result = array();
        $result[] = $parentId;
        $agent = Agent::find($parentId);
        if ($agent->parent != 0) {
            $this->listParent($agent->parent);
        }
        return $result;
    }

    // 開啟的遊戲商(包含繼承上層代理的)
    public function vendorList($memberId, $param = 'v.id', $vendor_id = 0, $show_disable = 0)
    {
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
            // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
            $array = explode(',', $agent->hierarchy);
            //頭尾兩個會是無效的
            for ($i = 1; $i < (count($array) - 1); $i++) {
                $data = DB::table('vendors as v')
                    ->join('agent_vendors as a', function ($join) use ($array, $i, $show_disable) {
                        $join->on('a.vendor_id', 'v.id')
                            ->where('a.agent_id', $array[$i]);
                        if ($show_disable == 0) {
                            $join->where('a.status', 1);
                        } else {
                            $join->whereIn('a.status', [0, 1]);
                        }
                    })
                    ->join('vendor_infos as vi', function ($join) use ($agent) {
                        $join->on('vi.id', 'a.vendor_info_id')
                            ->where('vi.wallet_type_id', $agent->wallet_type_id)
                            ->where('vi.status', 1);
                    })
                    ->where('v.status', '>', 0);
                if ($vendor_id > 0) {
                    $data = $data->where('v.id', $vendor_id);
                }
                $data = $data->pluck($param)
                    ->toArray();
                if (count($data) > 0) {
                    //查到有設定的帳號為止
                    return $data;
                    break;
                }
            }
            //如果都沒有設定的話就回傳空的
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => []
            ];
        } else {
            return [
                'code' => 1050,
                'message' => __('error_message.1050')
            ];
        }
    }

    public function getVendorList($memberId)
    {
        $member = Member::find($memberId);
        if (is_object($member)) {
            // 查代理資訊
            $agent = Agent::find($member->parent);
            if (is_object($agent)) {
                //最上層
                if ($agent->parent == 0) {
                    $data = DB::table('vendors as v')
                        ->join('agent_vendors as av', function ($join) use ($member) {
                            $join->on('av.vendor_id', 'v.id')
                                ->where('av.agent_id', $member->parent)
                                ->where('av.status', 1); //營運商有開啟的遊戲商
                        })
                        ->join('vendor_infos as vi', function ($join) use ($member) {
                            $join->on('vi.id', 'av.vendor_info_id')
                                ->where('vi.wallet_type_id', $member->wallet_type_id)
                                ->where('vi.status', 1);
                        })
                        ->where('v.status', '>', 0) //平台有開啟的遊戲商
                        ->pluck('v.id')
                        ->toArray();
                } else {
                    // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
                    $array = array_slice(explode(',', $agent->hierarchy), 2, -1);
                    $main = DB::table('vendors as v')
                        ->join('agent_vendors as av', function ($join) use ($array) {
                            $join->on('v.id', 'av.vendor_id')
                                ->whereIn('av.agent_id', $array);
                        })
                        ->join('vendor_infos as vi', function ($join) use ($member) {
                            $join->on('vi.id', 'av.vendor_info_id')
                                ->where('vi.wallet_type_id', $member->wallet_type_id)
                                ->where('vi.status', 1);
                        })
                        ->where('v.status', '>', 0) //平台有開啟的遊戲商
                        ->groupBy('v.id')
                        ->select(
                            'v.id',
                            DB::raw('MIN(a.status) AS status')
                        );
                    $sub = DB::table('vendors as v')
                        ->join('agent_vendors as a', function ($join) use ($member) {
                            $join->on('v.id', 'a.vendor_id')
                                ->where('a.agent_id', $member->parent);
                        })
                        ->select('v.id', 'a.status');
                    $data = DB::table(DB::raw('(' . $main->toSql(). ') as y'))
                        ->mergeBindings($main)
                        ->leftJoin(DB::raw('(' . $sub->toSql() . ') as z'), 'y.id', 'z.id')
                        ->mergeBindings($sub)
                        ->where(function ($query) {
                            $query->where('y.status', 1)
                                ->where('z.status', 1);
                        })
                        ->orWhere(function ($query) {
                            $query->where('y.status', 1)
                                ->whereNull('z.status');
                        })
                        ->pluck('y.id')
                        ->toArray();
                }
                if (count($data) > 0) {
                    //查到有設定的帳號為止
                    return $data;
                }
                //如果都沒有設定的話就回傳空的
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => []
                ];
            } else {
                return [
                    'code' => 1050,
                    'message' => __('error_message.1050'),
                ];
            }
        } else {
            return [
                'code' => 1050,
                'message' => __('error_message.1050'),
            ];
        }
    }

    public function agentGameList($member_id, $vendor_id = null)
    {
        $member = Member::find($member_id);
        $result = array();
        if (is_object($member)) {
            // 查代理資訊
            $agent = Agent::find($member->parent);
            if (is_object($agent)) {
                // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
                $agents = explode(',', $agent->hierarchy);
                unset($agents[0]); //頭尾兩個是沒有用的

                $vendors = (is_array($vendor_id) && count($vendor_id) > 0)
                    ? $vendor_id
                    : ($vendor_id == null
                        ? $this->vendorList($member_id)
                        : [$vendor_id]
                    );

                $redis = new REDIS();
                foreach ($vendors as $vendor) {
                    $key = array(
                        'agent_games',
                        $member->wallet_type_id,
                        $agent->id,
                        $vendor
                    );
                    if (!$redis->exists($key)) {
                        foreach ($agents as $agent_id) {
                            $model = DB::table('games as g')
                                ->join('agent_games as ag', function ($join) use ($agent_id) {
                                    $join->on('ag.game_id', 'g.id')
                                        ->where('ag.agent_id', $agent_id);
                                })
                                ->where('g.vendor_id', $vendor)
                                ->where('g.status', 1);
                            if ($model->count() > 0) {
                                $data = $model->where('ag.status', 1)
                                    ->pluck('g.id')
                                    ->toArray();
                                $result = array_merge($result, $data);
                                $redis->set($key, $data);
                                break;
                            }
                        }
                    } else {
                        $result = array_merge($result, $redis->get($key));
                    }
                }
            }
        }
        return (count($result) > 0) ? $result : [0];
    }

    // 可參一會活動編號
    public function eventList($memberId)
    {
        $member = Member::find($memberId);
        if (is_object($member)) {
            // 查代理資訊
            $agent = Agent::find($member->parent);
            if (is_object($agent)) {
                // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
                $array = explode(',', $agent->hierarchy);
                unset($array[0]); //頭尾兩個是沒有用的
                array_push($array, 0); //加上0
                $data = DB::table('events')->whereIn('agent_id', $array)
                    ->pluck('id')->toArray();
                return $data;
            } else {
                return [
                    'code' => 1050,
                    'message' => __('error_message.1050'),
                ];
            }
        } else {
            return [
                'code' => 1050,
                'message' => __('error_message.1050'),
            ];
        }
    }

    public function getArrayItemByColumnValue($array, $columnName, $targetValue)
    {
        $result = null;
        foreach ($array as $item) {
            if ($targetValue == $item->$columnName) {
                $result = $item;
                break;
            }
        }
        return $result;
    }

    public function getAgentHierarchyPath($member_id)
    {
        $agent = DB::table('members as m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('m.id', $member_id)
            ->select(
                'a.hierarchy_path'
            )
            ->first();
        return explode('/', substr($agent->hierarchy_path, 1));
    }

    public function getAgentAdvanceSetting($agent_id, $setting = null)
    {
        $agent = Agent::find($agent_id);
        $model = DB::table('agent_setting_defines as d')
            ->leftJoin('agent_customized_settings as s', function ($join) use ($agent) {
                $join->on('s.setting_key', 'd.setting_id')
                    ->where('s.agent_group_id', $agent->customized_setting_group_id);
            })
            ->leftJoin('agent_personal_settings as p', function ($join) use ($agent) {
                $join->on('p.setting_id', 'd.setting_id')
                    ->where('p.agent_id', $agent->id);
            })
            ->where(function ($query) {
                $query->where('d.data_type', '!=', 'language_text')
                    ->orWhere(function ($sub_query) {
                        $sub_query->where('d.data_type', 'language_text')
                            ->where('s.sub_category', 1);
                    });
            })
            ->where('d.is_visible', 1);

        if (is_array($setting) && count($setting) > 0) {
            $model = $model->whereIn('d.setting_id', $setting);
        } elseif (trim($setting) != '') {
            $model = $model->where('d.setting_id', $setting);
        }

        $model = $model->orderBy('d.setting_id', 'asc')
            ->select(
                'd.setting_id',
                'd.data_type',
                DB::raw('CASE d.type WHEN 2 THEN IFNULL(p.value, d.default_value) WHEN 3 THEN IFNULL(s.value, d.default_value) END AS value')
            )
            ->get();

        $data = array();
        if (is_object($model) > 0) {
            foreach ($model as $obj) {
                $value = null;
                switch ($obj->data_type) {
                    case 'boolean':
                        //$value = ($obj->value == 1) ? true : false;
                        $value = $obj->value * 1;
                        break;
                    case 'datetime':
                        $value = $obj->value;
                        break;
                    default:
                        $value = $obj->value;
                        break;
                }
                $data[$obj->setting_id] = $value;
            }
        }

        return $data;
    }

    //檢查手機號是否已存在
    public function checkMobileNumberExists($mobile, $organization_id)
    {
        $count = DB::table('members as m')
            ->join('agents as a', function ($join) use ($organization_id) {
                $join->on('a.id', 'm.parent')
                    ->where('a.organization_id', $organization_id);
            })
            ->where('m.mobile', $mobile)
            ->where('m.mobile_validate', 1)
            ->count();
        return ($count > 0) ? true : false;
    }

    //檢查手機號是否已存在
    public function checkMobileNumberExists2($mobile, $organization_id, $member_id = 0) {
        $count = DB::table('validate_tokens as vt')
            ->join('members as m', 'm.id', 'vt.member_id')
            ->join('agents as a', function ($join) use ($organization_id) {
                $join->on('a.id', 'm.parent')
                    ->where('a.organization_id', $organization_id);
            })
            ->where('example.invalid', $mobile)
            ->whereRaw('vt.expire_date >= NOW()')
            ->where('vt.member_id', '!=', $member_id)
            ->count();
        return ($count > 0) ? true : false;
    }

    //回傳組織ID; 查不到就回傳0
    public function getOrganizationIdByDomain($domain)
    {
        $row = DB::table('agent_domains as ad')
            ->join('agents as a', function ($join) {
                $join->on('a.id', 'ad.agent_id')
                    ->where('a.status', 1);
            })
            ->where('ad.domain', $domain)
            ->where('ad.status', 1)
            ->select(
                'a.organization_id'
            )
            ->first();
        return (is_null($row)) ? 0 : $row->organization_id;
    }

    //檢查會員是否為鎖分模式;
    public function checkMemberEventLockByMemberId($member_id)
    {
        return Member::where('id', $member_id)->pluck('event_mode')->first();
    }

    //加密邀請玩家活動邀請碼
    public function eventCodeEncrypt($event_id, $member_id)
    {
        //用"IE+活動ID(4碼)+玩家ID(7碼)來做註冊碼"
        $str = substr('0000' . $event_id, -4) . substr('0000000' . $member_id, -7);
        //補齊8碼; 因為99999999999加密為2T47DPVV, 10000001加密為9H5K1
        $param = substr('0000' . strtoupper(base_convert($str, 10, 32)), -8);
        return env('EVENT_CODE_PREFIX', 'IE') . $param;
    }

    //解密邀請玩家活動邀請碼
    public function eventCodeDecrypt($str)
    {
        $str = substr($str, strlen(env('EVENT_CODE_PREFIX', 'IE')));
        //解密完將位數補齊至11位數
        $param = substr('000' . base_convert($str, 32, 10), -11);
        return [
            'event_id' => (substr($param, 0, 4) * 1),
            'member_id' => (substr($param, 4) * 1)
        ];
    }
}
