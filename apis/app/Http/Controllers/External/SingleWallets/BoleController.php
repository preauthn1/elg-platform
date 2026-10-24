<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\BoleswAccount;
use App\Vendors\Models\BoleswBetRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BoleController extends BaseController
{
    protected int $vendorId = VendorIdEnum::BOLESW->value;

    protected string $accountClass = BoleswAccount::class;

    protected string $betRecordClass = BoleswBetRecord::class;

    protected string $actionPrefix = 'Bolesw_get_request_';

    protected string $vendorInfoOperatorField = 'authId';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                $this->errorResponse('IP not in whitelist');
            }

            $params = $request->all();
            $username = $params['player_account'] ?? '';
            $app_id = $params['app_id'] ?? '';

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse('member not found');
            }

            // 取得商戶 需要放在取得用戶後面
            $this->vendorInfo = $this->getVendorInfo($app_id, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse('app_id error');
            }

            // 驗證簽名
            if (!$this->checkSign($params)) {
                return $this->errorResponse('sign error');
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

            return $this->errorResponse('system error');
        }
    }

    /**
     * 查詢餘額
     */
    public function balance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse('member not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 錢包加扣點
     */
    public function cost(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得變化金額 (正數)
            $changeAmount = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);

            // 类型 10=上分(扣錢) 11=上分失败回滚(加錢) 20=下分(加錢)
            $type = $params['type'];

            $isMinus = $isAdd = $isSettle = $isRefund = false;

            // 扣錢
            if ($type == 10 && $changeAmount > 0) {
                $isMinus = true;
            }

            // 加錢
            if ($type == 20 && $changeAmount > 0) {
                $isAdd = true;
            }

            // 退錢
            if ($type == 11 && $changeAmount > 0) {
                $isRefund = true;
            }

            // 結算
            $infoType = $params['cost_info']['cost_type'] ?? '';
            if ($infoType == 12) {
                $isSettle = true;
            }

            // 都不是
            if (!$isAdd && !$isMinus && !$isSettle && !$isRefund) {
                return $this->errorResponse('invalid type');
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['report_id'])->first();

            // 檢查重複訂單
            // 一般正常订单流程是：
            // 上分 type 10 -> 下分 type 20 同一条记录
            // 上分 type 10 -> 上分失败回滚 type 11 同一条记录
            // type 11 或 20 代表訂單已經結束
            if ($oldBetRecord) {
                if (
                    $oldBetRecord->type == 20
                    || $oldBetRecord->type == 11
                    || ($oldBetRecord->type == 10 && $type == 10)
                ) {
                    return $this->errorResponse('repeat order');
                }
            }

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['report_id'],
                    'reference_bet_id' => 0,
                    'username' => $params['player_account'],
                    'game_code' => $params['game_code'],
                    'type' => $type,
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
                $betRecord->type = $type;
            }

            // 更新遊戲紀錄
            if ($isAdd) {
                $betRecord->win = Common::numAdd($betRecord->win, $changeAmount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = date('Y-m-d H:i:s', $params['time']);
            }
            if ($isMinus) {
                $betRecord->bet = Common::numAdd($betRecord->bet, $changeAmount, 4);
                $betRecord->valid_bet = $betRecord->bet;
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->bet_time = date('Y-m-d H:i:s', $params['time']);
            }

            if ($isRefund) {
                $betRecord->bet = 0;
                $betRecord->valid_bet = 0;
                $betRecord->win = 0;
                $betRecord->win_lose = 0;
                $betRecord->payout_time = date('Y-m-d H:i:s', $params['time']);
            }

            if ($isSettle) {
                $betRecord->bet = $params['cost_info']['bet_num'];
                $betRecord->valid_bet = $betRecord->bet;
                $betRecord->win_lose = $params['cost_info']['gain_gold'];
                $betRecord->win = Common::numAdd($betRecord->win_lose, $betRecord->bet, 4);
                $betRecord->settle_status = 1;
                $betRecord->payout_time = date('Y-m-d H:i:s', $params['time']);
            }

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance);
        });
    }

    protected function checkSign($params)
    {
        $authKey = $this->vendorInfo->info['authKey'];
        $signData = [
            'operator_id' => $params['operator_id'],
            'operator_sub_id' => $params['operator_sub_id'],
            'player_account' => $params['player_account'],
            'app_key' => $authKey,
        ];
        $str = '';
        foreach ($signData as $key => $value) {
            $str .= $key . '=' . $value . '&';
        }
        $str = rtrim($str, '&');

        $sign = strtoupper(base64_encode(hash_hmac('sha1', $str, $authKey, true)));

        return $sign == $params['sha1'];
    }

    protected function errorResponse($msg = 'success')
    {
        return response()->json([
            'data' => [
                'balance' => 0,
                'currency' => $this->vendorInfo->currency ?? 'HKD',
            ],
            'status' => [
                'code' => -1,
                'msg' => $msg,
            ],
        ]);
    }

    protected function response($balance, $code = 0, $msg = 'success')
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'data' => [
                'balance' => $balance,
                'currency' => $this->vendorInfo->currency ?? 'HKD',
            ],
            'status' => [
                'code' => $code,
                'msg' => $msg,
            ],
        ]);
    }
}
