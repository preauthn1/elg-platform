<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Enums\ErrorCodeEnum;
use App\Vendors\WudiSW\WudiSWService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-wudisw
 */
class WudiSWController extends BaseController
{
    private array $requestInfos;

    private WudiSWService $service;

    private string $startTime;

    public function callAction($method, $parameters)
    {
        try {
            $action = 'Wudi_SW_request_' . basename(url()->current());
            $request = request();

            Log::info($action, [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
                'ContentType' => $request->getContentTypeFormat(),
                'Content' => $request->getContent(),
                'IP' => Common::getIp(),
            ]);

            if (!Common::verifyIPWhitelistByVendorId(VendorIdEnum::WUDISW->value)) {
                $requestIp = Common::getIp();

                return $this->errorLogAndResponse(ErrorCodeEnum::SOMETHING_WRONG->value, "IP {$requestIp} not allow");
            }

            $this->requestInfos = [
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
            ];
            $this->startTime = Carbon::now()->setTimezone('America/New_York')->toRfc3339String(); // utc-4
            $this->service = new WudiSWService;

            if (!$this->service->validate($request)) {
                return $this->errorLogAndResponse(ErrorCodeEnum::AUTHORIZATION_INVALID->value, 'Invalid signature');
            }

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response

            return $response;
        } catch (Exception $e) {
            // 重複請求（如注單已結算／已退款）依文件須回 code 1000 並帶 data（目前餘額），否則三方無法結單
            if ($e->getCode() === ErrorCodeEnum::SUCCESS->value) {
                return response()->json([
                    'status' => [
                        'code' => ErrorCodeEnum::SUCCESS->value,
                        'message' => $e->getMessage(),
                        'time' => $this->startTime,
                    ],
                    'data' => [
                        'balance' => (float) $this->service->getCurrentBalance($request->input('username')),
                        'currency' => $this->service->getCurrency(),
                        'tran_id' => $request->input('tran_id'),
                    ],
                ]);
            }

            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    public function getUserBalance(Request $request)
    {
        $balance = $this->service->getBalance($request->username);

        return response()->json([
            'status' => [
                'code' => ErrorCodeEnum::SUCCESS->value,
                'message' => 'Success.',
                'time' => $this->startTime,
            ],
            'data' => [
                'balance' => (float) $balance,
                'currency' => $this->service->getCurrency(),
                'tran_id' => $request->input('tran_id'),
            ],
        ]);
    }

    public function transfer(Request $request)
    {
        $dto = TransferInfoData::from($request->all());
        switch ($request->input('transfer_type')) {
            case 'start':
                $balance = $this->service->transferStart($dto);
                break;
            case 'end':
                $balance = $this->service->transferEnd($dto);
                break;
            case 'refund':
                $balance = $this->service->transferRefund($dto);
                break;
            case 'reward':
                $balance = $this->service->transferReward($dto);
                break;
            default:
                throw new Exception('Invalid transfer type', ErrorCodeEnum::SOMETHING_WRONG->value);
        }

        return response()->json([
            'status' => [
                'code' => ErrorCodeEnum::SUCCESS->value,
                'message' => 'Success.',
                'time' => $this->startTime,
            ],
            'data' => [
                'balance' => (float) $balance,
                'currency' => $this->service->getCurrency(),
                'tran_id' => $request->input('tran_id'),
            ],
        ]);
    }

    private function errorLogAndResponse(int $errorCode, string $errorMessage = ''): JsonResponse
    {
        $responseData = [
            'status' => [
                'code' => $errorCode,
                'message' => $errorMessage,
                'time' => $this->startTime,
            ],
        ];

        $logData = [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
        ];

        Log::error("WudiSW_error_$errorCode", $logData);

        return response()->json($responseData, 200);
    }
}
