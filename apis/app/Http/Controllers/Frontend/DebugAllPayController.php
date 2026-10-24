<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Payment\Providers\AllPay\AllPayTrait;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Group('Debug (測試用、小工具)')]
class DebugAllPayController extends Controller
{
    use AllPayTrait;

    protected array $info = [
        'MerchantNo' => 'AEE505291',
        'MerchantKey' => '64BDC6149C65B6313C7558B8FA007E2F',
        'hasPaymentCode' => '1',
        // 'hasBankCode' => '1',
        'paymentCode' => 'P2PDEPOSIT',
        'bankCode' => 'VNDMOMO',
    ];

    // protected array $info = [
    //     'MerchantNo' => 'AEE505291',
    //     'MerchantKey' => '64BDC6149C65B6313C7558B8FA007E2F',
    //     'hasPaymentCode' => '1',
    //     // 'hasBankCode' => '1',
    //     // 'paymentCode' => 'VNDQR',
    //     'bankCode' => 'VNDMOMO',
    // ];

    public function tryAllPay(Request $request)
    {
        $params = [
            'amount' => '100000',
            'memberCode' => 'qaBen001',
            'mobile' => '60857452413',
            'notifyUrl' => 'https://example.invalid/api/cashflow/7',
            'orderNo' => Str::uuid7()->toString(),
            'orderTime' => Carbon::now()->toFrontendTime()->format('YmdHis'),
            'returnUrl' => 'https://example.invalid/Deposit#result',
        ];

        if (isset($this->info['hasPaymentCode']) && $this->info['hasPaymentCode'] == 1) {
            $params['paymentCode'] = $this->info['bankCode'];
        }

        if (isset($this->info['hasBankCode']) && $this->info['hasBankCode'] == 1) {
            $params['paymentCode'] = $this->info['paymentCode'];
            $params['bankCode'] = $this->info['bankCode'];
        }

        // lad($params);
        // return ApiResponse::success($params);

        $result = $this->sendRequest('https://example.invalid/qrh5', $params);

        return ApiResponse::success($result);
    }

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

    protected function sendRequest(string $url, array $params): array
    {
        $action = class_basename(static::class);

        // $ApiUrl = $this->info['APIUrl'];
        // if (Str::endsWith($ApiUrl, '/qrh5')) {
        //     $ApiUrl = rtrim($ApiUrl, '/qrh5');
        // }
        // $url = rtrim($ApiUrl, '/') . $url;
        // $url = 'https://example.invalid/qrh5';

        $encodeParams = $this->makeRequest($params);
        // lad($encodeParams);

        $result = Http::logAction($action)
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
}
