<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Vendors\FumaSW\Enums\ActionEnum;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\FumaSW\FumaSWService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-fumasw
 */
class FumaSWController extends BaseController
{
    private array $requestInfos = [];

    public function __construct(Request $request, private FumaSWService $fumaSWService)
    {
        $action = 'FumaSW_request_' . basename(url()->current());
    }

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = 'FumaSW_request_' . basename(url()->current());

            $requestId = microtime(true);

            Log::info($action, [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
                'ContentType' => $request->getContentTypeFormat(),
                'Content' => $request->getContent(),
                'IP' => Common::getIp(),
                'requestId' => $requestId,
            ]);

            $this->requestInfos = [
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
                'requestId' => $requestId,
            ];

            if (!Common::verifyIPWhitelistByVendorId(VendorIdEnum::FUMASW->value)) {
                $requestIp = Common::getIp();

                return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, sprintf('IP %s not allow', $requestIp));
            }

            $this->fumaSWService->decodeRequestAndValidateSignature($request);

            // 紀錄解密後的資料
            // 將 transferId 轉換為字串, 避免 int 型態的 transferId 造成 log 錯誤
            $params = $request->all();
            if (isset($params['transferId'])) {
                $params['transferId'] = (string) $params['transferId'];
            }
            $this->requestInfos['params'] = $params;

            Log::info($action . '_decrypted', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $params,
                'ContentType' => $request->getContentTypeFormat(),
                'Content' => $request->getContent(),
                'IP' => Common::getIp(),
                'requestId' => $requestId,
            ]);

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response

            return $response;
        } catch (\Throwable $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    public function apiSwitch(Request $request)
    {
        $action = $request->input('action');

        switch ($action) {
            case ActionEnum::BALANCE:
                return $this->balance($request);
            case ActionEnum::BET:
                return $this->bet($request);
            case ActionEnum::SETTLE:
                return $this->settle($request);
            default:
                return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, 'Invalid action');
        }
    }

    private function balance(Request $request)
    {
        $request->validate([
            'uid' => 'required|string',
            'currency' => 'required|string',
        ]);

        $balance = $this->fumaSWService->getBalance($request->uid);

        return response()->json([
            'status' => '0000',
            'balance' => (float) Common::numFloor($balance, 2),
        ]);
    }

    private function bet(Request $request)
    {
        $balance = $this->fumaSWService->bet($request);

        return response()->json([
            'status' => '0000',
            'balance' => (float) Common::numFloor($balance, 2),
        ]);
    }

    private function settle(Request $request)
    {
        $balance = $this->fumaSWService->settle($request);

        return response()->json([
            'status' => '0000',
            'balance' => (float) Common::numFloor($balance, 2),
        ]);
    }

    private function errorLogAndResponse(int $errorCode, string $errorMessage = ''): JsonResponse
    {
        if ($errorCode === 0) {
            $errorCode = ErrorCodeEnum::SYSTEM_ERROR;
        }

        $responseData = [
            'code' => $errorCode,
            'msg' => $errorMessage,
        ];

        $logData = [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
        ];

        Log::info("FumaSW_SW_error_$errorCode", $logData);

        return response()->json($responseData);
    }
}
