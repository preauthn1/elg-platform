<?php

namespace App\Http\Controllers\External\TransferWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Models\MemberAccessToken;
use App\Vendors\Models\NsAccount;
use App\Vendors\Models\NsBetRecord;
use App\Vendors\NextSpin\ErrorCodeEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 注意 SpadeGamingController 繼承此 controller
 */
class NextSpinController extends BaseController
{
    protected int $vendorId = VendorIdEnum::NS->value;

    protected string $accountClass = NsAccount::class;

    protected string $betRecordClass = NsBetRecord::class;

    protected string $vendorInfoOperatorField = 'Merchant_Code';

    protected string $actionPrefix = 'NS_get_request_';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $endpoint = basename(url()->current());
            $action = $this->actionPrefix . $endpoint;

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(ErrorCodeEnum::REQUEST_FORBIDDEN, 'IP not in whitelist');
            }

            $params = $request->all();
            $username = $params['acctId'];
            $token = [REDACTED]'token'];
            $operatorId = $params['merchantCode'];

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(ErrorCodeEnum::ACCT_NOT_FOUND);
            }

            // 驗證 token
            if ($token != $this->getToken()) {
                return $this->errorResponse(ErrorCodeEnum::TOKEN_VALIDATION_FAILED);
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(ErrorCodeEnum::MERCHANT_NOT_FOUND);
            }

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response

            return $response;
        } catch (\Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';
            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR);
        }
    }

    /**
     * 驗證玩家
     */
    public function authorize(Request $request)
    {
        // 轉帳錢包的餘額固定為0
        return $this->response(0, 0, 'success', $request->input('serialNo'));
    }

    /**
     * 取得 token
     * 需要與開啟遊戲api的規則一致
     */
    protected function getToken(): string
    {
        return MemberAccessToken:[REDACTED]
            ->where('member_id', $this->member->id)
            ->orderBy('id', 'DESC')
            ->first()
            ->token ?? '';
    }

    protected function response($balance, $code, $message, $serialNo)
    {
        $acctInfo = [
            'acctId' => $this->account->username,
            'balance' => 0,
            'currency' => $this->vendorInfo->currency,
        ];

        return response()->json([
            'acctInfo' => $acctInfo,
            'merchantCode' => $this->vendorInfo->info[$this->vendorInfoOperatorField],
            'msg' => $message,
            'code' => $code,
            'serialNo' => $serialNo,
        ]);
    }

    protected function errorResponse(ErrorCodeEnum $code, string $msg = '')
    {
        if (empty($msg)) {
            $msg = $code->name;
        }

        return response()->json([
            'code' => $code->value,
            'msg' => $msg,
        ]);
    }
}
