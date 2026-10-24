<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AllPay;

use App\Exceptions\ApiException;
use App\Services\Payment\Traits\DriverTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait AllPayTrait
{
    use DriverTrait;

    protected function sendRequest(string $path, array $params): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        // info 內的 APIUrl 已經是完整的儲值網址, 不需要再拼接路徑
        // ex: https://example.invalid/qrh5
        // ex: https://example.invalid/api/Deposit/manual-cashier
        // ex: https://example.invalid/cashier
        $url = $this->info['APIUrl'];

        // 查詢訂單時 需要拼接 query 路徑
        // 取得網域加上 /query 路徑
        if ($path == '/query') {
            // 取得 網域
            $arr = parse_url($url);
            $url = $arr['scheme'] . '://' . $arr['host'] . '/query';
        }

        $encodeParams = $this->makeRequest($params);
        // lad($encodeParams);

        $result = Http::logAction($action)
            ->withOptions(['verify' => false]) // AllPay 憑證缺少 SAN，停用 SSL 驗證
            ->asForm()
            ->post($url, $encodeParams)
            ->throw()
            ->json();

        if (!isset($result['resCode']) || $result['resCode'] != '000') {
            Log::error($this->mapResCodeToMessage($result['resCode']));
            throw new ApiException($result['resMsg']);
        }

        // 確認商戶號吻合
        if (!isset($result['merchantNo']) || $result['merchantNo'] != $this->info['MerchantNo']) {
            throw new ApiException('Merchant number mismatch');
        }

        // 解密 response
        $response = json_decode(base64_decode($result['response']), true);
        // lad($response);

        Log::debug('AllPay ' . $action, [
            'url' => $url,
            'merchantNo' => $this->info['MerchantNo'],
            'params' => $params,
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * 封裝請求
     */
    protected function makeRequest(array $params): array
    {
        $data = [
            'version' => '1.0',
            'merchantNo' => $this->info['MerchantNo'],
            'request' => base64_encode(json_encode($params)),
        ];

        // 簽名處理
        ksort($data);
        $signMsg = '';
        foreach ($data as $k => $v) {
            $signMsg .= $k . ':' . $v . '&';
        }
        $signMsg .= 'key:' . $this->info['MerchantKey'];

        $data['signMsg'] = strtoupper(md5($signMsg));

        return $data;
    }

    protected function mapResCodeToMessage(string $code): string
    {
        return match ($code) {
            '000' => 'success',
            '101' => 'API does not exist',
            '202' => 'Check no Account number',
            '203' => 'Account suspension',
            '204' => 'Account locked',
            '205' => 'Account freeze',
            '206' => 'Account configuration error',
            '301' => 'wrong version parameter',
            '302' => 'merchantNo parameter error',
            '303' => 'Request parameter error',
            '304' => 'signMsg error',
            '401' => 'Verification failed',
            '402' => 'Encryption and decryption failed',
            '403' => 'Malformed ciphertext',
            '404' => 'Cipher text format error',
            '999' => 'Internal System Error',
            default => 'Unknown error: ' . $code,
        };
    }

    protected function makeSignature(array $input): string
    {
        // 組合簽名字串
        $fields = [
            'content' => $input['content'] ?? '',
            'notifyTime' => $input['notifyTime'] ?? '',
            'version' => $input['version'] ?? '',
        ];

        $tmpSignature = '';
        foreach ($fields as $k => $v) {
            $tmpSignature .= $k . ':' . $v . '&';
        }
        $tmpSignature .= 'key:' . $this->info['MerchantKey'];

        return strtoupper(md5($tmpSignature));
    }
}
