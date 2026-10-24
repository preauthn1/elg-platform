<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\RollingChipLog;
use App\Services\RollingChipService;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\WmliveswAccount;
use App\Vendors\Models\WmliveswBetRecord;
use App\Vendors\Models\WmliveswDealRecord;
use ErrorException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WmLiveSwController extends BaseController
{
    protected int $vendorId = VendorIdEnum::WMLIVESW->value;

    protected string $actionPrefix = 'WmLive_SW_request_';

    protected string $accountClass = WmliveswAccount::class;

    protected string $betRecordClass = WmliveswBetRecord::class;

    protected string $dealRecordModel = WmliveswDealRecord::class;

    // 驗證 VendorInfo 的欄位
    protected string $vendorInfoOperatorField = 'SIGNATURE';

    /**
     * 單一入口
     */
    public function __invoke(Request $request)
    {
        try {
            // 驗證請求參數
            $request->validate([
                'cmd' => 'required|string',
                'signature' => 'required|string',
                'user' => 'nullable|string',
            ]);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse('IP not in whitelist');
            }

            $params = $request->all();
            $cmd = $params['cmd'] ?? '';
            $signature = $params['signature'] ?? '';
            $username = $params['user'] ?? '';

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member && $cmd != 'SendMemberReport') {
                throw new ErrorException('member not found');
            }

            // 取得商戶 需要放在取得用戶後面
            $this->vendorInfo = $this->getVendorInfo($signature, $this->account);
            if (!$this->vendorInfo) {
                throw new ErrorException('vendor info not found');
            }

            // 紀錄請求日誌
            $this->logRequest($request, $this->actionPrefix . $cmd);

            return $this->selectAction($cmd, $params);

        } catch (\Throwable $e) {
            // 統一錯誤處理
            Log::emergency($this->actionPrefix . 'error: ' . $e->getMessage());

            return $this->errorResponse('system error');
        }
    }

    public function selectAction(string $cmd, array $params)
    {
        return match ($cmd) {
            'CallBalance' => $this->getBalance($params),
            'PointInout' => match ($params['code'] ?? null) {
                '0', '2', '4' => $this->deductBalance($params),
                '1', '3', '5' => $this->increaseBalance($params),
                default => throw new ErrorException('The code is illegal'),
            },
            'TimeoutBetReturn' => $this->rollbackBalance($params),
            'SendMemberReport' => $this->settlement($params),
            default => throw new ErrorException('The command is illegal'),
        };
    }

    /**
     * 錯誤信息格式
     */
    private function errorResponse($errorMessage, $errorCode = 400): JsonResponse
    {
        if ($errorCode === 0) {
            $errorCode = 400;
        }

        return response()->json([
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
            'result' => '',
        ]);
    }

    /**
     * 正確信息格式
     */
    private function successResponse(array $data = []): JsonResponse
    {
        return response()->json([
            'errorCode' => 0,
            'errorMessage' => '',
            'result' => $data,
        ]);
    }

    /**
     * 建立交易紀錄 wmlivesw_deal_records
     *
     * @throws Exception
     */
    protected function createDealRecord(string $amount, array $params): WmliveswDealRecord
    {
        // 換算匯率成我方幣值
        $amount = Common::numDiv($params['money'], $this->vendorInfo->rate, 4);

        // 建立交易紀錄
        $dealRecord = $this->dealRecordModel::firstOrCreate(
            [
                'deal_id' => $params['dealid'],
            ],
            [
                'deal_id' => $params['dealid'],
                'deal_code' => $params['code'], // 加扣点类型 0:电子游戏结算 1:加点 2:扣点 3:重对加点 4:重对扣点 5:重新派彩
                'member_id' => $this->member->id,
                'account_id' => $this->account->id,
                'username' => $this->account->username,
                'amount' => $amount,
                'game_code' => $params['gtype'], // 101:百家乐,102:龙虎,103:轮盘,104:骰宝,105:牛牛,107:番摊,108:色碟
                'request_time' => Carbon::parse($params['requestDate'])->toSystemTime()->toDateTimeString(), // 三方請求時間
                'status' => 0, // 處理後再更新為 1:交易成功, 2:交易失敗(餘額不足)
                'raw_data' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), // 原始數據
            ]);

        if (!$dealRecord) {
            throw new Exception('Insert deal record failed');
        }

        // 交易紀錄已存在
        if ($dealRecord->wasRecentlyCreated === false) {
            throw new Exception(sprintf('Deal record #%s already processed', $dealRecord->deal_id));
        }

        return $dealRecord;
    }

    /**
     * 查詢餘額
     *
     * @see https://example.invalid/wallet_index.html#GetBalance
     */
    private function getBalance(array $params)
    {
        if ($this->account->status == 0) {
            throw new ErrorException('member not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return $this->successResponse([
            'user' => $params['user'],
            'money' => $balance,
            'responseDate' => Carbon::now()->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 錢包加點 (派彩)
     *
     * @see https://example.invalid/wallet_index.html#PointInout
     */
    private function increaseBalance(array $params)
    {
        return DB::transaction(function () use ($params) {
            // 交易內第一個 SQL 必須是鎖會員列，之後的一般讀（deal record 冪等查詢）才是現值（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $gameWallet = $walletDto->wallet; // 遊戲錢包 (Member or MemberWallet)

            // 換算匯率成我方幣值
            $amount = Common::numDiv($params['money'], $this->vendorInfo->rate, 4);

            // 查詢交易紀錄
            $oldDealRecord = $this->dealRecordModel::query()->where('deal_id', $params['dealid'])->first();

            if ($oldDealRecord) {
                return $this->errorResponse('Deal record already exists');
            }

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($amount, $params);

            // 調整真金
            $balanceBefore = $gameWallet->balance;
            $balanceAfter = Common::numAdd($gameWallet->balance, $amount, 4);

            // 更新錢包餘額
            if ($this->account->status == 1) {
                $gameWallet->update([
                    'balance' => $balanceAfter,
                ]);
            }

            // 真金交易紀錄
            $transactionLogService = new TransactionLogCreateService($this->member, $this->vendorInfo, $walletDto->isMultipleWallet, $this->account);
            $transactionLog = $transactionLogService->create($amount, $balanceBefore, $balanceAfter);
            $transactionLogId = $transactionLog->id;

            /**
             * 更新交易紀錄
             */
            $dealRecord->update([
                'status' => 1, // 交易成功
                'transaction_log_id' => $transactionLogId,
            ]);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return $this->successResponse([
                'dealid' => $params['dealid'],
                'money' => $params['money'],
                'cash' => $newBalance,
                'responseDate' => $dealRecord->updated_at->toFrontendTime()->toDateTimeString(),
            ]);
        });
    }

    /**
     * 錢包扣點 (下注)
     *
     * @see https://example.invalid/wallet_index.html#PointInout
     */
    private function deductBalance(array $params)
    {
        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gtype']);
        if (!$ableToBet) {
            return $this->errorResponse('Account could not bet yet');
        }

        return DB::transaction(function () use ($params) {
            // 交易內第一個 SQL 必須是鎖會員列，之後的一般讀（deal record 冪等查詢）才是現值（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $rollingChipWallet = $walletDto->rollingChipWallet; // 禮碼錢包
            $gameWallet = $walletDto->wallet; // 遊戲錢包 (Member or MemberWallet)

            // 換算匯率成我方幣值
            $amount = Common::numDiv($params['money'], $this->vendorInfo->rate, 4);

            // 查詢交易紀錄
            $oldDealRecord = $this->dealRecordModel::query()->where('deal_id', $params['dealid'])->first();

            if ($oldDealRecord) {
                return $this->errorResponse('Deal record already exists');
            }

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($amount, $params);

            // 如果餘額不足，則拋出異常
            if ($balance < $amount) {
                throw new Exception('Insufficient balance');
            }

            // 調整禮碼
            $chipPayAmount = 0;
            if ($rollingChipWallet && $rollingChipWallet->balance > 0) {
                $rollingChipService = new RollingChipService($this->member, $walletDto->rollingChipWallet);
                $chipPayResult = $rollingChipService->sub((float) $amount, RollingChipLogTypeEnum::BET_SETTLE);
                $chipPayAmount = Common::numSub($chipPayResult->beforeBalance, $chipPayResult->afterBalance);
            }

            // 調整真金
            $unpaidBetAmount = Common::numSub($amount, $chipPayAmount, 4);
            $balanceBefore = $gameWallet->balance;
            $balanceAfter = Common::numSub($gameWallet->balance, $unpaidBetAmount, 4);

            // 更新錢包餘額
            if ($this->account->status == 1) {
                $gameWallet->update([
                    'balance' => $balanceAfter,
                ]);
            }

            // 真金交易紀錄
            $transactionLogService = new TransactionLogCreateService($this->member, $this->vendorInfo, $walletDto->isMultipleWallet, $this->account);
            $transactionLog = $transactionLogService->create($unpaidBetAmount, $balanceBefore, $balanceAfter);
            $transactionLogId = $transactionLog->id;

            /**
             * 更新交易紀錄
             */
            $dealRecord->update([
                'status' => 1, // 交易成功
                'transaction_log_id' => $transactionLogId,
                'rolling_chips' => $chipPayAmount,
            ]);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return $this->successResponse([
                'dealid' => $params['dealid'],
                'money' => $params['money'],
                'cash' => $newBalance,
                'responseDate' => $dealRecord->updated_at->toFrontendTime()->toDateTimeString(),
            ]);
        });
    }

    /**
     * 錢包回滾
     *
     * @see https://example.invalid/wallet_index.html#Betfeedback
     */
    private function rollbackBalance(array $params)
    {
        return DB::transaction(function () use ($params) {
            // 交易內第一個 SQL 必須是鎖會員列，之後的一般讀（deal record 狀態判斷）才是現值（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $rollingChipWallet = $walletDto->rollingChipWallet; // 禮碼錢包
            $gameWallet = $walletDto->wallet; // 遊戲錢包 (Member or MemberWallet)

            // 回滾的金額，正數代表派彩回滾，負數代表下注回滾，統一轉為正數
            $money = ltrim((string) $params['money'], '-');

            // 換算匯率成我方幣值
            $refundAmount = Common::numDiv($money, $this->vendorInfo->rate, 4);

            // 查詢交易紀錄
            $dealRecord = $this->dealRecordModel::query()->where('deal_id', $params['dealid'])->first();

            if (!$dealRecord) {
                return $this->errorResponse('Deal record does not exists');
            }

            /**
             * 檢查回滾條件
             */
            // 已回滾過，拒絕回滾
            if ($dealRecord->is_rollback) {
                throw new Exception('Repeated request rollback');
            }

            $rejectRollback = true;

            // 當扣點成功時若有回滾，同意回滾(取消訂單，並增加點數)
            if ($dealRecord->status == 1 && $dealRecord->deal_code == 2) {
                $rejectRollback = false;
            }

            // 當派彩失敗時若有回滾，同意回滾(重試訂單，並增加點數)
            if ($dealRecord->status == 2 && $dealRecord->deal_code == 1) {
                $rejectRollback = false;
            }

            if ($rejectRollback) {
                throw new Exception('Transaction was completed and reject to be rollback');
            }

            // 從交易紀錄判斷要回滾的真金與禮碼分別是多少
            [$walletRefund, $rollingChipRefund] = $this->splitRefundAmount($dealRecord, $refundAmount);

            // 使用禮碼必須優先回滾禮碼
            if ($rollingChipRefund > 0) {
                // 禮碼回滾
                $this->refundToRollingChipWallet($rollingChipWallet, $rollingChipRefund);
            }

            // 真金回滾
            $transactionLogId = $this->refundToGameWallet($gameWallet, $walletRefund, $walletDto->isMultipleWallet);

            // 更新交易紀錄
            $dealRecord->update([
                'is_rollback' => 1, // 已回滾
                'raw_data' => $this->appendParamsToInfo($dealRecord->raw_data, $params),
                'transaction_log_id' => $transactionLogId,
            ]);

            // 現下餘額, 轉換為三方匯率
            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return $this->successResponse([
                'balance' => $newBalance,
                'updated_at' => $dealRecord->updated_at->toFrontendTime()->toDateTimeString(),
            ]);
        });
    }

    /**
     * 會員結算 (一次多筆投注紀錄)
     *
     * @see https://example.invalid/wallet_index.html#SendMemberReport
     * ! 此接口需要特別請三方打開
     */
    private function settlement(array $params)
    {
        DB::transaction(function () {
            $result = $this->params['result'] ?? [];

            foreach ($result as $round) {
                // 取得用戶
                $this->member = $this->getMemberByThirdPartyUsername($round['user']);

                // 建立投注紀錄
                $betRecord = $this->betRecordClass::query()->firstOrCreate(
                    [
                        'bet_id' => $round['betId'],
                    ],
                    [
                        'bet_id' => $round['betId'],
                        'member_id' => $this->member->id,
                        'account_id' => $this->account->id,
                        'username' => $this->account->username,
                        'game_code' => $round['gid'], // 游戏类别编号 101:百家乐,102:龙虎,103:轮盘,104:骰宝,105:牛牛,107:番摊,108:色碟
                        'bet_amount' => Common::numDiv($round['bet'], $this->vendorInfo->rate, 4), // 下注金额
                        'valid_bet' => Common::numDiv($round['validbet'], $this->vendorInfo->rate, 4), // 有效投注額
                        'win_lose' => Common::numDiv($round['winLoss'], $this->vendorInfo->rate, 4), // 输赢金额
                        'bet_deal_id' => Str::replaceLast(',', '', $round['betwalletid']), // 下注的 dealid 如該场次下注多笔则用 ',' 分开
                        'result_deal_id' => $round['resultwalletid'], // 派彩的 dealid
                        'bet_time' => Carbon::parse($round['betTime'])->toSystemTime()->timestamp, // 三方請求時間 UTC
                        'settlement_time' => Carbon::parse($round['settime'])->toSystemTime()->timestamp, // 結算時間 UTC
                        'status' => 0, // 我方處理狀態, 0:未處理, 1:完成, 2:失敗
                        'raw_data' => json_encode($round, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), // 原始數據
                    ]
                );

                if (!$betRecord->wasRecentlyCreated && $betRecord->status === 1) {
                    continue;
                }

                try {
                    // 投注的 deal_id
                    $dealIds = explode(',', $betRecord->bet_deal_id);

                    // 加上派採的 deal_id
                    if ($betRecord->result_deal_id !== '-1') {
                        $dealIds[] = $betRecord->result_deal_id;
                    }

                    // 更新關聯的交易紀錄為已結算
                    $this->dealRecordModel::query()
                        ->lockForUpdate()
                        ->whereIn('deal_id', $dealIds)
                        ->where('is_settlement', 0)
                        ->update([
                            'is_settlement' => 1,
                            'bet_id' => DB::raw('IF(bet_id IS NULL OR bet_id = "", ' . $betRecord->bet_id . ', concat(bet_id, ",", ' . $betRecord->bet_id . '))'),
                        ]);

                    // 加總交易紀錄使用的禮碼
                    $totalRollingChips = $this->dealRecordModel::query()
                        ->lockForUpdate()
                        ->where('bet_id', $betRecord->bet_id)
                        ->where('is_settlement', 1)
                        ->where('is_rollback', 0)
                        ->sum('rolling_chips');

                    /**
                     * 取最後一筆交易紀錄的 transaction_log_id
                     */
                    $transactionLogId = $this->dealRecordModel::query()
                        ->whereIn('deal_id', $dealIds)
                        ->select('transaction_log_id')
                        ->latest('id')
                        ->first();

                    // 寫入投注紀錄總表 TotalBetRecord
                    $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $this->member->agent->is_multi_wallet);
                    $totalBetRecordService->create($betRecord, $transactionLogId);

                    // 更新注單狀態 && 使用禮碼
                    $betRecord->update([
                        'rolling_chips' => $totalRollingChips,
                        'status' => 1,
                    ]);
                } catch (Exception $e) {
                    Log::emergency($this->actionPrefix . 'settlement error: ' . $e->getMessage());
                    // 更新注單狀態失敗
                    $betRecord->update([
                        'status' => 2,
                    ]);
                }
            }

            return $this->successResponse();
        });
    }

    /**
     * 拆分退款金額 => 錢包退款 + 禮碼退款
     *
     * @throws Exception
     */
    protected function splitRefundAmount($dealRecord, $refundAmount): array
    {
        // 金額負轉正
        $betAmount = abs($dealRecord->amount);  // 下注金額
        $refundAmount = abs($refundAmount);     // 退款金額

        // 遊戲錢包投注金額 = 下注金額 - 禮碼投注金額
        $betAmount = Common::numSub($betAmount, $dealRecord->rolling_chips);
        $rollingChipBetAmount = $dealRecord->rolling_chips ?? 0;

        // 優先返回遊戲錢包
        if ($betAmount >= $refundAmount) {
            $walletRefund = $refundAmount; // 遊戲錢包退款
            $refundAmount = 0;
        } else {
            $walletRefund = $betAmount;
            $refundAmount = Common::numSub($refundAmount, $walletRefund);
        }

        // 返回禮碼
        if ($rollingChipBetAmount >= $refundAmount) {
            $rollingChipRefund = $refundAmount; // 禮碼錢包退款
            $refundAmount = 0;
        } else {
            $rollingChipRefund = $rollingChipBetAmount;
            $refundAmount = Common::numSub($refundAmount, $rollingChipBetAmount);
        }

        if ($refundAmount > 0) {
            // 退款金額大於投注金額
            throw new Exception('Payback amount is greater than wallet refund and rolling chip refund');
        }

        return [$walletRefund, $rollingChipRefund];
    }

    /**
     * 退款到禮碼錢包
     *
     * @throws Exception
     */
    protected function refundToRollingChipWallet($rollingChipWallet, $refundAmount): int
    {
        if (!$rollingChipWallet) {
            throw new Exception('Rolling chip wallet not found');
        }

        $rollingChipBefore = $rollingChipWallet->balance;

        $rollingChipWallet->increment('balance', $refundAmount);

        $rollingChipAfter = Common::numAdd($rollingChipBefore, $refundAmount);

        $rollingChipLog = RollingChipLog::create([
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendorInfo->vendor_id,
            'type' => RollingChipLogTypeEnum::BET_CANCEL,
            'amount' => $refundAmount,
            'before_balance' => $rollingChipBefore,
            'balance' => $rollingChipAfter,
            'status' => 1000,
            'remark' => '',
            'admin' => 0,
        ]);

        if (!$rollingChipLog) {
            throw new Exception('Insert rolling chip log failed');
        }

        $rollingChipLogId = $rollingChipLog->id;

        return $rollingChipLogId;
    }

    /**
     * 退款到遊戲錢包
     *
     * @throws Exception
     */
    protected function refundToGameWallet(MemberWallet|Member $gameWallet, $walletRefund, $isMultipleWallet): int
    {
        $walletBalanceBefore = $gameWallet->balance;

        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $walletRefund);

        if ($walletRefund > 0) {
            $gameWallet->increment('balance', $walletRefund);
        }

        // 真金交易紀錄
        $transactionLogService = new TransactionLogCreateService($this->member, $this->vendorInfo, $isMultipleWallet, $this->account);
        $transactionLog = $transactionLogService->create($walletRefund, $walletBalanceBefore, $walletBalanceAfter);
        $transactionLogId = $transactionLog->id;

        return $transactionLogId;
    }
}
