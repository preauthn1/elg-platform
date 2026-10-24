<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use App\Services\Encryption\AesService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SocketTrait
{
    public static function postToSocket($url, $data)
    {
        // 將資料加密
        // 這裡需要建立新的實例, 因為 App:make() 會取到 Frontend 的 AES 設定, 但這裡是 CS 的 AES 設定
        $aesService = new AesService(
            cipher: config('encrypt.third_party.cipher'),
            key: config('encrypt.third_party.key'),
            iv: config('encrypt.third_party.iv')
        );
        $params = $aesService->encrypt(json_encode($data));
        $key = md5($params . config('encrypt.third_party.md5'));

        $postData = [
            'params' => $params,
            'key' => $key,
        ];

        // 呼叫客服 API
        $apiUrl = config('custom.api_cs_url') . $url;
        $action = '請求客服 API ' . $apiUrl;

        try {
            $response = Http::logAction($action)->timeout(30)
                ->withOptions(['verify' => false])
                ->post($apiUrl, $postData);

            $result = $response->body();

            Log::info('客服 API 響應' . $result);

            return $response->json();
        } catch (Exception $e) {
            Log::error('請求客服 API 異常' . $e->getMessage());

            return null;
        }
    }
}
