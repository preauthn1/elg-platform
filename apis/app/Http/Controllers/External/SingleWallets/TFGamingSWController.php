<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\TFGamingSW\TransferRequest;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use App\Vendors\TFGamingSW\TFGamingSWService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TFGamingSWController extends Controller
{
    private array $requestInfos = [];

    public function __construct(Request $request)
    {
        $action = 'TFGaming_SW_request_' . basename(url()->current());

        Log::info($action, [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => Common::flattenHeaders($request->headers->all()),
            'params' => $request->all(),
            'ContentType' => $request->header('Content-Type'),
            'Content' => $request->getContent(),
            'IP' => Common::getIp(),
        ]);

        if (!Common::verifyIPWhitelistByVendorId(VendorIdEnum::TFGAMINGSW->value)) {
            $requestIp = Common::getIp();
            throw new HttpResponseException(response()->json([
                'Status' => ErrorCodeEnum::INVALID_OPERATOR->value,
                'Description' => "IP {$requestIp} not allow",
                'ResponseDateTime' => date('Y-m-d H:i:s'),
            ]));
        }

        $this->requestInfos = [
            'headers' => Common::flattenHeaders($request->headers->all()),
            'params' => $request->all(),
        ];
    }

    /**
     * tfgaming/wallet
     * - 取得遊戲錢包金額
     */
    public function wallet(Request $request)
    {
        try {
            $request->validate(['loginName' => 'required|string']);

            $balance = TFGamingSWService::getBalance($request->loginName);

            return response()->json([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $balance,
            ]);

        } catch (\Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    /**
     * tfgaming/transfer
     * - 遊戲轉帳
     */
    public function transfer(TransferRequest $request)
    {
        try {
            $transferInfoDTO = TransferInfoData::from(['data' => $request->all()]);

            if ($transferInfoDTO->isPlaceBet) {
                TFGamingSWService::bet($transferInfoDTO);
            } else {
                switch ($transferInfoDTO->description) {
                    case 'Unsettlement':
                        TFGamingSWService::unsettle($transferInfoDTO);
                        break;
                    case 'Loss':
                    case 'Settlement':
                        TFGamingSWService::settle($transferInfoDTO);
                        break;
                    default:
                        throw new \Exception('Invalid description', ErrorCodeEnum::UNDEFINED->value);
                }
            }

            return response()->json([
                'code' => ErrorCodeEnum::SUCCESS,
                'msg' => 'success',
            ]);
        } catch (\Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    /**
     * tfgaming/rollback
     * - 遊戲撤單
     */
    public function rollback(Request $request)
    {
        try {
            $transferInfoDTO = new TransferInfoData($request->all());
            TFGamingSWService::rollback($transferInfoDTO);

            return response()->json([
                'code' => ErrorCodeEnum::SUCCESS,
                'msg' => 'success',
            ]);
        } catch (\Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    private function errorLogAndResponse(int $errorCode, string $errorMessage = ''): JsonResponse
    {
        $responseData = [
            'code' => $errorCode,
            'msg' => $errorMessage,
        ];

        $logData = [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
        ];

        Log::error("TFGaming_SW_error_$errorCode", $logData);

        return response()->json($responseData, 400);
    }
}
