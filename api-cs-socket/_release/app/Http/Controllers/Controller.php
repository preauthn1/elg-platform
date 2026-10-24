<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use App\ThirdParty;
use App\User;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    //取使用者ip
    function getIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['HTTP_VIA'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    //分頁
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

    //上傳圖片到gcp
    function uploadBase64File($file, $folder = '', $is_public = true)
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
            $this->compress($path, 30, $path);
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
                $filesystem->write($folder . $fileName, fopen($path, 'r'), array(
                    'visibility' => 'public'
                ));
                $fileName = $adapter->getUrl($folder . $fileName);
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

    function compress($source, $quality = 50, $destination = '')
    {
        ini_set('memory_limit', -1); // 以防内存问题
        $info = getimagesize($source);

        if ($info['mime'] == 'image/jpeg') {
            $image = imagecreatefromjpeg($source);
        } elseif ($info['mime'] == 'image/gif') {
            $image = imagecreatefromgif($source);
        } elseif ($info['mime'] == 'image/png') {
            $image = imagecreatefrompng($source);
        }

        if ($destination) {
            // 如果指定保存文件路径
            imagejpeg($image, $destination, $quality);

            return $destination;
        } else {
            // 直接输出 image stream
            ob_start();
            imagejpeg($image, null, $quality);
            $data = ob_get_clean();

            return $data;
        }
    }

    //呼叫第三方取得玩家資訊
    function getMemberInfo($agentId, $memberId)
    {
        $third_party = new ThirdParty($agentId);
        $data = $third_party->getInfo($memberId);
        if ($data == -1) {
            return null;
        } else {
            return $data;
        }
    }

    //呼叫第三方取得餘額
    function getBalance($agentId, $memberId)
    {
        $third_party = new ThirdParty($agentId);
        $data = $third_party->getInfo($memberId);
        if ($data == -1) {
            return null;
        } else {
            return $data['balance'];
        }
    }

    //確認第三方密碼是否正確
    function checkPassword($agentId, $memberId, $password)
    {
        $third_party = new ThirdParty($agentId);
        return $third_party->checkPassword($memberId, $password);
    }

    //第三方金額轉入轉出
    function transaction($agentId, $memberId, $platform, $amount, $transactionType, $remark = '', $operatorId = 0, $userId = 0)
    {
        $third_party = new ThirdParty($agentId);
        return $third_party->transaction($memberId, $platform, $amount, $transactionType, $remark, $operatorId, $userId);
    }

    function listChild($parentId)
    {
        static $result = array();
        $result[] = $parentId;
        //子帳號的資料也同時查出來
        $rows = User::where('parent_id', $parentId)->get();
        foreach ($rows as $row) {
            $this->listChild($row['id']);
        }
        return $result;
    }

    function listChildWithHost($parentId)
    {
        static $result = array();
        $result[] = $parentId;
        //子帳號的資料也同時查出來
        $rows = User::where('parent_id', $parentId)->orWhere('host_id', $parentId)->get();
        foreach ($rows as $row) {
            $this->listChildWithHost($row['id']);
        }
        return $result;
    }
}