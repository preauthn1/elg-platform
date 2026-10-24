<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use App\Agent;
use DB;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $service;

    function getIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['HTTP_VIA'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    function getPagination($total, $prePage, $page)
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

    function uploadPhoto($file, $is_public = true)
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

    function uploadBase64File($file, $is_public = true)
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

    function isChild($parentId, $childId)
    {
        //查詢下線帳號的上線有沒有父帳號
        $agent = Agent::where('id', $childId)->where('hierarchy', 'like', '%,' . $parentId . ',%')->first();
        if (is_object($agent)) {
            return true;
        } else {
            return false;
        }

        // static $result = array();
        // $rows = Agent::where('parent', $parentId)->get();
        // foreach ($rows as $row) {
        //     if ($row['id'] == $childId) {
        //         $result[] = true;
        //     } else {
        //         $this->isChild($row['id'], $childId);
        //         $result[] = false;
        //     }
        // }
        // return in_array(true, $result);
    }

    function listChild($parentId)
    {
        static $result = array();
        $result[] = $parentId;
        $rows = Agent::where('parent', $parentId)->get();
        foreach ($rows as $row) {
            $this->listChild($row['id']);
        }
        return $result;
    }

    // 開啟的遊戲商(包含繼承上層代理的)
    function vendorList($agentId)
    {
        // 查代理資訊
        $agent = Agent::find($agentId);
        if (is_object($agent)) {
            // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
            $array = explode(',', $agent->hierarchy);
            //頭尾兩個會是無效的
            for ($i = 1; $i < (count($array) - 1); $i++) {
                $data = DB::table('vendors as v')
                    ->leftJoin('agent_vendors as a', 'a.vendor_id', '=', 'v.id')
                    ->where('v.status', '>', 0) //平台有開啟的遊戲商
                    ->where('a.backend_show', 1) //營運商有開啟的遊戲商(後台可查詢的)
                    ->where('a.agent_id', $array[$i])
                    ->pluck('v.id')->toArray();
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
                'message' => __('error_message.1050'),
            ];
        }
    }
}