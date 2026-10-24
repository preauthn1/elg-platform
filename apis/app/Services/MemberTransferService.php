<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\VendorTransferData;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Enums\WalletEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventV2WalletTransactionLog;
use App\Models\Member;
use App\Models\PromotionWalletLog;
use App\Models\TransactionLog;
use App\Models\TransferLimit;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorRequestFail;
use App\Models\WalletTransactionLog;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MemberTransferService
{
    public function __construct(
        private LargeAmountNotifyService $largeAmountNotifyService,
    ) {}

    /**
     * 將近三個月有轉出紀錄的遊戲商餘額全部轉回本地
     *
     * 由 MemberTransferController::transferBackRecent 與 V1 鎖分活動（PrizeService）共用。
     * 單一遊戲商失敗只記錄不中斷，維持既有行為。
     */
    public function transferBackRecentVendors(Member $member): void
    {
        $vendorService = new VendorService;

        $vendorIds = $this->getTransferOutVendorIds(
            member: $member,
            start: Carbon::now()->subMonths(3)->startOfDay(),
        );

        $maintainVendorIds = $vendorService->getMaintenanceVendorIds(
            agent: $member->agent,
            status: [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::WALLET_CLOSED],
        );

        foreach ($vendorIds as $vendorId) {
            if (in_array($vendorId, $maintainVendorIds)) {
                continue;
            }

            try {
                $vendorDriver = $vendorService->getDriver($vendorId, $member);
            } catch (VendorSetupException $e) {
                Log::error(sprintf('[TransferBackRecent] 遊戲商設定例外 vendor_id=%s: %s', $vendorId, $e->getMessage()));

                continue;
            }

            if (!($vendorDriver instanceof AbstractTransferWallet)) {
                continue;
            }

            try {
                $this->transferToLocal($vendorDriver);
            } catch (Throwable $e) {
                Log::error(sprintf('[TransferBackRecent] 轉回本地失敗 vendor_id=%s: %s', $vendorId, $e->getMessage()));
            }
        }
    }

    /**
     * 查詢最後一筆轉入的紀錄
     */
    public function getLastTransferOutTransactionLog(Member $member): null|TransactionLog|WalletTransactionLog
    {
        $agent = $member->agent;

        // 取得活躍的遊戲商 ID（使用 VendorService 並帶快取）
        $vendorService = App::make(VendorService::class);
        $activeVendorIds = $vendorService->getVendorIds($agent);

        // 是否有參加活動
        if ($member->is_event_wallet) {
            return WalletTransactionLog::query()
                ->where('member_id', $member->id)
                ->where('type', WalletTransactionEnum::EVENT_WALLET_TO_VENDOR)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
                ->whereIn('vendor_id', $activeVendorIds)
                ->orderByDesc('created_at')
                ->first();
        }

        // 代理是否為多錢包
        if ($agent->is_multi_wallet === true) {
            $log = WalletTransactionLog::query()
                ->where('member_id', $member->id)
                ->where('type', WalletTransactionEnum::GAME_WALLET_TO_VENDOR)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
                ->whereIn('vendor_id', $activeVendorIds)
                ->orderByDesc('created_at')
                ->first();

            if ($log) {
                return $log;
            }
            // 如果沒轉過點，有可能是剛開多錢包，可能有餘額留在遊戲商, 需要改查詢 TransactionLog
        }

        return TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', TransactionEnum::TO_VENDOR)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
            ->whereIn('vendor_id', $activeVendorIds)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * 查詢一段時間內有轉入餘額到遊戲商的遊戲商ID
     */
    public function getTransferOutVendorIds(Member $member, Carbon $start): array
    {
        $agent = $member->agent;

        // 取得活躍的遊戲商 ID（使用 VendorService 並帶快取）
        $vendorService = App::make(VendorService::class);
        $activeVendorIds = $vendorService->getVendorIds($agent);

        // 代理是否為多錢包
        if ($agent->is_multi_wallet === true) {
            $vendorIds = WalletTransactionLog::query()
                ->where('member_id', $member->id)
                ->where('type', WalletTransactionEnum::GAME_WALLET_TO_VENDOR)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
                ->where('created_at', '>=', $start)
                ->whereIn('vendor_id', $activeVendorIds)
                ->orderByDesc('created_at')
                ->select(['vendor_id'])
                ->distinct()
                ->get()
                ->pluck('vendor_id')
                ->toArray();

            if ($vendorIds) {
                return $vendorIds;
            }
            // 如果沒轉過點，有可能是剛開多錢包，可能有餘額留在遊戲商, 需要改查詢 TransactionLog
        }

        return TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', TransactionEnum::TO_VENDOR)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
            ->where('created_at', '>=', $start)
            ->whereIn('vendor_id', $activeVendorIds)
            ->orderByDesc('created_at')
            ->select(['vendor_id'])
            ->distinct()
            ->get()
            ->pluck('vendor_id')
            ->toArray();
    }

    /**
     * 將會員在遊戲商的餘額轉回本地
     *
     * @param  string|null  $transferInAmount  本地錢包額度
     * @return array 回傳的 vendor_balance 已轉換為本地錢包匯率
     *
     * @throws Throwable
     *
     * @noinspection D
     */
    public function transferToLocal(AbstractTransferWallet $vendorDriver, ?string $transferInAmount = null): array
    {
        $member = $vendorDriver->member;
        $vendor = $vendorDriver->vendor;

        Log::withContext([
            'vendor_name' => $vendorDriver->getName(),
            'vendor_id' => $vendor->id,
            'transfer_in_amount' => $transferInAmount,
        ]);

        // 取得遊戲商餘額
        try {
            $vendorBalance = $vendorDriver->getBalance();
        } catch (Throwable $e) {
            // vendor_request_fails 是「三方掛了」的訊號（vendor_detect 據此探測、自動掛維護）；
            // 三方查無此帳號是單一玩家的狀態，不算三方故障，不寫
            if (!$e instanceof AccountNotExistsException) {
                VendorRequestFail::create([
                    'vendor_id' => $vendor->id,
                    'general_agent_id' => $member->agent->general_agent_id ?? 0,
                    'member_id' => $member->id,
                ]);
            }

            Log::error('【轉回本地】查詢遊戲商餘額失敗，未進入轉帳流程', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::debug('【轉回本地】開始處理');

        // 沒有指定轉入金額就將遊戲商餘額全轉回
        if (is_null($transferInAmount)) {
            $vendorWithdrawalAmount = $vendorBalance;
        } else {
            $vendorWithdrawalAmount = Common::numMul($transferInAmount, $vendorDriver->getRate());
        }

        // 實際從遊戲商提領金額 (去除不足以轉出的尾數)
        $vendorWithdrawalAmount = Common::truncatePointsByUnit($vendorWithdrawalAmount, $vendorDriver->getTransferMinAmount());
        // 截斷後換算回本地幣別（與 transferToVendor 的 $localTransferAmount 對稱）
        $localTransferAmount = Common::numDiv($vendorWithdrawalAmount, $vendorDriver->getRate(), 4);

        Log::withContext([
            'local_transfer_amount' => $localTransferAmount,
            'vendor_withdrawal_amount' => $vendorWithdrawalAmount,
        ]);
        Log::debug('【轉回本地】轉帳金額計算完成');

        // 依據是否為多錢包模式, 取得會員錢包
        /** @var WalletService $walletService */
        $walletService = App::make(WalletService::class);
        $memberWallets = $walletService->getMemberLockedWallets($member);

        $localTransferAmountCompare = Common::numCompare($localTransferAmount, 0);
        if ($localTransferAmountCompare === 0) {
            $vendorRemainingBalance = Common::numDiv($vendorBalance, $vendorDriver->getRate());

            Log::info('【轉回本地】轉回金額換算後為 0，直接結束（不呼叫三方轉帳）');

            return [
                'withdrawal_amount' => '0',
                'balance' => $memberWallets->wallet->balance,
                'vendor_balance' => $vendorRemainingBalance,
            ];
        } elseif ($localTransferAmountCompare === -1) {
            Log::error('【轉回本地】失敗：轉回金額為負值（不呼叫三方轉帳）');
            throw new ApiException(__('error_message.transfer in cannot be negative'));
        }

        // 取款金額不得大於遊戲商錢包餘額
        $isVendorBalanceEnough = Common::numCompare($vendorBalance, $vendorWithdrawalAmount) >= 0;
        if (!$isVendorBalanceEnough) {
            Log::error('【轉回本地】失敗：遊戲商餘額不足（不呼叫三方轉帳）', [
                'vendor_balance' => $vendorBalance,
            ]);
            throw new ApiException(__('error_message.1022'));
        }

        $this->largeAmountNotifyService->notifyVendorTransferOut($member, $vendor, $localTransferAmount);

        $transactionLogData = [
            'transaction_no' => $vendorDriver->getTransactionNo(TransactionEnum::FROM_VENDOR),
            'vendor_id' => $vendor->id,
            'point' => $localTransferAmount,
            'vendor_point' => $vendorWithdrawalAmount,
            'rate' => $vendorDriver->getRate(),
            'before_balance' => $memberWallets->wallet->balance, // 轉點前玩家餘額, 這裡沒有 lock 所以金額可能不正確，但沒關係，成功後會更新為正確金額
            'balance' => 0,
            'vendor_balance' => 0,
            'status' => TransactionStatusEnum::STATUS_FAIL->value,
            'remark' => '',
        ];

        // 建立交易紀錄，先設定為失敗，不在 transaction 內操作，避免交易紀錄被 rollback 後刪除沒有失敗紀錄
        // type / wallet_type_id 由 createTransactionLog 依 baseType 自動解析
        $transactionLog = $this->createTransactionLog(
            member: $member,
            vendorDriver: $vendorDriver,
            data: $transactionLogData,
            baseType: TransactionEnum::FROM_VENDOR,
        );

        // 注入 log 類型，讓後續所有 log 均可辨識使用的是哪種交易紀錄 Model
        Log::withContext([
            'transaction_log' => [
                'type' => class_basename($transactionLog),
                'id' => $transactionLog->id,
                'no' => $transactionLog->transaction_no,
            ],
        ]);

        Log::debug('【轉回本地】已建立交易紀錄');

        // 遊戲商餘額應扣除本次取款金額, 平台紀錄用需轉換為本地錢包匯率
        $vendorRemainingBalance = Common::numSub($vendorBalance, $vendorWithdrawalAmount);
        $vendorRemainingBalance = Common::numDiv($vendorRemainingBalance, $vendorDriver->getRate());

        // 呼叫遊戲商轉帳 API
        $postData = VendorTransferData::from([
            'transactionLogId' => $transactionLog->id,
            'transactionNo' => $transactionLog->transaction_no,
            'vendorAmount' => $vendorWithdrawalAmount,
        ]);

        Log::debug('【轉回本地】準備呼叫三方轉帳');

        $result = $vendorDriver->transferToLocal($postData);
        $logLevel = ($result != VendorTransferResultEnum::SUCCESS) ? 'error' : 'info';
        Log::log($logLevel, '【轉回本地】三方轉帳已回應');

        try {
            DB::beginTransaction();

            if ($result === VendorTransferResultEnum::SUCCESS) {
                $memberWallets = $walletService->getMemberLockedWallets($member);

                // 更新會員餘額，這裡才用 wallet->balance 計算才是正確的金額，且避免 race condition.
                $memberBeforeBalance = Common::numAdd('0', (string) $memberWallets->wallet->balance, 4);
                $memberAfterBalance = Common::numAdd($memberBeforeBalance, $localTransferAmount, 4);
                $memberWallets->wallet->update([
                    'balance' => $memberAfterBalance,
                ]);

                // 更新交易紀錄, before 也要更新為 lock 後的正確金額
                $transactionLog->update([
                    'before_balance' => $memberBeforeBalance,
                    'balance' => $memberAfterBalance, // 不能直接用 wallet->balance 因為沒有 refresh，也不需要因為已經計算好了
                    'vendor_balance' => $vendorRemainingBalance,
                    'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                ]);
            } elseif ($result === VendorTransferResultEnum::FAILED) {
                // 明確失敗, 不更新餘額
                Log::error('會員轉回本地失敗：三方回傳明確失敗（FAILED）');
                throw new ApiException(__('error_message.9995'));
            } elseif ($result === VendorTransferResultEnum::TIMEOUT) {
                // 超時或不預期回傳處理，不還玩家錢，並記錄失敗紀錄
                Log::error('會員轉回本地失敗：三方回應超時（TIMEOUT）');
                $transactionLog->update([
                    'status' => TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value,
                    'remark' => 'timeout',
                ]);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            // 若三方已成功扣款（遊戲商餘額已減少）但本地 DB 寫入失敗，屬於轉丟
            if ($result === VendorTransferResultEnum::SUCCESS) {
                $this->logTransferLost($member, $vendor->id, $vendor->name, $localTransferAmount, $transactionLog, '三方扣款成功，本地DB寫入失敗');

                // 記錄 DB 錯誤詳情便於診斷（其他上下文資訊已由 withContext 記錄）
                Log::critical('【轉丟診斷】DB 寫入失敗詳情', [
                    'error_message' => $e->getMessage(),
                    'error_code' => $e->getCode() ?? 'unknown',
                    'error_class' => class_basename($e),
                    'sql_state' => $e instanceof \PDOException ? $e->errorInfo[0] ?? null : null,
                ]);
            }
            throw $e;
        }

        return [
            'withdrawal_amount' => $localTransferAmount,
            'balance' => $memberWallets->wallet->balance,
            'vendor_balance' => $vendorRemainingBalance,
        ];
    }

    /**
     * 將會員本地餘額轉入遊戲
     *
     * @return array{balance: string, vendor_balance: string}
     *                                                        balance        — 本地餘額
     *                                                        vendor_balance — 轉帳後遊戲商餘額（遊戲商幣別）；
     *                                                        若查詢失敗，則改用估算值
     *
     * @throws ApiException
     * @throws Throwable
     * @throws BindingResolutionException
     *
     * @noinspection D
     */
    public function transferToVendor(AbstractTransferWallet $vendorDriver, ?string $transferOutAmount = null): array
    {
        $member = $vendorDriver->member;
        $vendor = $vendorDriver->vendor;

        // 依據是否為多錢包模式, 取得會員錢包
        $walletService = App::make(WalletService::class);

        // 沒有指定轉出金額就將本地餘額全轉出
        if (is_null($transferOutAmount)) {
            $memberWallets = $walletService->getMemberLockedWallets($member);
            $transferOutAmount = Common::numAdd('0', (string) $memberWallets->wallet->balance, 4);
            Log::warning('【轉入遊戲商】未指定轉出金額，預設使用本地錢包餘額');
        }

        Log::withContext([
            'vendor_name' => $vendorDriver->getName(),
            'vendor_id' => $vendor->id,
            'transfer_out_amount' => $transferOutAmount,
        ]);

        Log::debug('【轉入遊戲商】開始處理');

        // 取得遊戲商餘額
        try {
            $vendorBalance = $vendorDriver->getBalance();
        } catch (Throwable $e) {
            // 同 transferToLocal：三方查無此帳號不是三方故障，不寫 vendor_request_fails
            if (!$e instanceof AccountNotExistsException) {
                VendorRequestFail::create([
                    'vendor_id' => $vendor->id,
                    'general_agent_id' => $member->agent->general_agent_id ?? 0,
                    'member_id' => $member->id,
                ]);
            }

            Log::error('【轉入遊戲商】查詢遊戲商餘額失敗，未進入扣款/轉帳流程', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 轉換為遊戲商幣別金額（local × rate = vendor），以便與遊戲商餘額比較及進行截斷
        $vendorDepositAmount = Common::numMul($transferOutAmount, $vendorDriver->getRate());
        // 轉入遊戲商幣別金額 (依遊戲商最小轉帳單位截斷尾數，再換算回本地幣別以取得精確扣款金額)
        $vendorDepositAmount = Common::truncatePointsByUnit($vendorDepositAmount, $vendorDriver->getTransferMinAmount());
        // 實際轉出金額
        $localTransferAmount = Common::numDiv($vendorDepositAmount, $vendorDriver->getRate(), 4);

        Log::withContext([
            'local_transfer_amount' => $localTransferAmount,
            'vendor_deposit_amount' => $vendorDepositAmount,
        ]);
        Log::debug('【轉入遊戲商】轉帳金額計算完成');

        $vendorDepositAmountCompare = Common::numCompare($vendorDepositAmount, 0);
        if ($vendorDepositAmountCompare === 0) {
            // 若要轉入遊戲商的金額換算後為 0，不扣款、不呼叫三方轉帳，直接回傳成功
            // vendor_balance 直接回傳 getBalance() 原始值（遊戲商幣別）
            Log::info('【轉入遊戲商】轉入遊戲商金額為 0，直接結束（不扣款、不呼叫三方轉帳）');

            return [
                'balance' => $transferOutAmount,
                'vendor_balance' => $vendorBalance, // 遊戲商幣別
            ];
        } elseif ($vendorDepositAmountCompare === -1) {
            Log::error('【轉入遊戲商】失敗：轉入金額為負值（不扣款、不呼叫三方轉帳）');
            throw new ApiException(__('error_message.transfer out cannot be negative'));
        }

        $this->largeAmountNotifyService->notifyVendorTransferIn($member, $vendor, $localTransferAmount);

        // 遊戲商餘額應加上本次轉入金額
        // 注意：$vendorRemainingBalance 為本地幣別，僅用於 transaction_log DB 儲存，不用於回傳
        $vendorRemainingBalance = Common::numDiv(
            Common::numAdd($vendorBalance, $vendorDepositAmount),
            $vendorDriver->getRate(),
        );

        $transactionLogData = [
            'transaction_no' => $vendorDriver->getTransactionNo(TransactionEnum::TO_VENDOR),
            'vendor_id' => $vendor->id,
            'point' => $localTransferAmount,
            'vendor_point' => $vendorDepositAmount,
            'rate' => $vendorDriver->getRate(),
            'before_balance' => $transferOutAmount, // 這裡沒有 lock 所以金額可能不正確，但沒關係，成功後會更新為正確金額
            'balance' => $transferOutAmount, // 起始還沒有扣錢，所以是原本的餘額
            'vendor_balance' => $vendorBalance, // initial value
            'status' => TransactionStatusEnum::STATUS_FAIL->value,
            'remark' => '',
        ];

        // 建立交易紀錄，先設定為失敗，不在 transaction 內操作，避免交易紀錄被 rollback 後刪除沒有失敗紀錄
        // type / wallet_type_id 由 createTransactionLog 依 baseType 自動解析
        $transactionLog = $this->createTransactionLog(
            member: $member,
            vendorDriver: $vendorDriver,
            data: $transactionLogData,
            baseType: TransactionEnum::TO_VENDOR,
        );

        // 注入 log 類型，讓後續所有 log 均可辨識使用的是哪種交易紀錄 Model
        Log::withContext([
            'transaction_log' => [
                'type' => class_basename($transactionLog),
                'id' => $transactionLog->id,
                'no' => $transactionLog->transaction_no,
            ],
        ]);

        Log::debug('【轉入遊戲商】已建立交易紀錄');

        // Step1: 扣掉餘額
        try {
            DB::beginTransaction();

            $memberWallets = $walletService->getMemberLockedWallets($member);

            $memberBeforeBalance = Common::numAdd('0', (string) $memberWallets->wallet->balance, 4);
            $memberAfterBalance = Common::numSub($memberBeforeBalance, $localTransferAmount, 4);

            if (Common::numCompare($memberAfterBalance, 0) < 0) {
                Log::error('【轉入遊戲商】步驟1：餘額不足', [
                    '扣款前餘額' => (string) $memberBeforeBalance,
                    '扣款金額' => $localTransferAmount,
                ]);
                throw new ApiException(__('error_message.1022'));
            }

            $memberWallets->wallet->update([
                'balance' => $memberAfterBalance,
            ]);

            $transactionLog->update([
                'point' => $localTransferAmount,
                'before_balance' => $memberBeforeBalance,
                'balance' => $memberAfterBalance,
                'remark' => '【轉入遊戲商】步驟1：已鎖定並扣除本地餘額，等待三方結果。',
            ]);

            DB::commit();

            Log::debug('【轉入遊戲商】步驟1完成：本地扣款成功', [
                '扣款前餘額' => $memberBeforeBalance,
                '扣款金額' => $localTransferAmount,
                '扣款後餘額' => $memberAfterBalance,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('【轉入遊戲商】步驟1失敗：本地扣款/交易更新發生例外', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step2: 呼叫遊戲商轉帳 API
        $postData = VendorTransferData::from([
            'transactionLogId' => $transactionLog->id,
            'transactionNo' => $transactionLog->transaction_no,
            'vendorAmount' => $vendorDepositAmount,
        ]);

        Log::debug('【轉入遊戲商】步驟2：準備呼叫三方轉帳', [
            'vendor_amount' => $vendorDepositAmount,
        ]);

        try {
            $result = $vendorDriver->transferToVendor($postData);
        } catch (Throwable $e) {
            // 發生例外, 標記為逾時不退錢
            Log::error('【轉入遊戲商】步驟2失敗：三方轉帳發生例外', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $remark = '【轉入遊戲商】步驟2失敗：三方轉帳發生例外,' . substr($e->getMessage(), 0, 150);

            $transactionLog->update([
                'status' => TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value,
                'remark' => $remark,
            ]);

            $this->logTransferLost($member, $vendor->id, $vendor->name, $localTransferAmount, $transactionLog, '三方轉帳發生例外');

            // 拋出錯誤，讓上層 catch 處理
            throw $e;
        }

        // step3: 依照轉帳結果更新交易紀錄
        try {
            DB::beginTransaction();
            if ($result === VendorTransferResultEnum::SUCCESS) {
                $transactionLog->update([
                    'vendor_point' => $vendorDepositAmount,
                    'vendor_balance' => $vendorRemainingBalance,
                    'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                ]);

                Log::debug('【轉入遊戲商】步驟3完成：轉帳成功，已更新交易紀錄', [
                    '本地扣款金額' => $localTransferAmount,
                    '遊戲商轉入金額' => $vendorDepositAmount,
                ]);
            } elseif ($result === VendorTransferResultEnum::FAILED) {
                // 明確失敗, 退錢給玩家
                $memberWallets = $walletService->getMemberLockedWallets($member);
                $memberBeforeBalance = $memberWallets->wallet->balance;
                $memberAfterBalance = Common::numAdd($memberBeforeBalance, $localTransferAmount, 4);
                $memberWallets->wallet->update([
                    'balance' => $memberAfterBalance,
                ]);

                // 退款 type 須與原始轉帳紀錄的 Enum 命名空間保持一致：
                // is_event_wallet / is_multi_wallet → WalletTransactionLog，使用 WalletTransactionEnum
                // 一般會員 → TransactionLog，使用 TransactionEnum
                $refundTransactionLogData = array_merge($transactionLogData, [
                    'type' => $member->is_event_wallet || $member->agent->is_multi_wallet
                        ? WalletTransactionEnum::TO_VENDOR_FAILED_REFUND->value
                        : TransactionEnum::TO_VENDOR_FAILED_REFUND->value,
                    'transaction_no' => $vendorDriver->getTransactionNo(TransactionEnum::TO_VENDOR),
                    'point' => $localTransferAmount,
                    'before_balance' => $memberBeforeBalance,
                    'balance' => $memberAfterBalance,
                    'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                    'remark' => '【轉入遊戲商】步驟2失敗：已退回本地餘額，ref_id:' . $transactionLog->id,
                ]);
                $refundLog = $this->createTransactionLog($member, $vendorDriver, $refundTransactionLogData);

                // 更新原本的交易紀錄為已退回平台餘額
                $transactionLog->update([
                    'status' => TransactionStatusEnum::STATUS_TO_VENDOR_FAILED_REFUND->value,
                ]);

                Log::debug('【轉入遊戲商】步驟3完成：三方失敗，已退回本地餘額並建立退款紀錄', [
                    '原交易_no' => $transactionLog->transaction_no,
                    '原交易_log_id' => $transactionLog->id,
                    '退款交易_no' => $refundLog->transaction_no ?? null,
                    '退款交易_log_id' => $refundLog->id ?? null,
                    '退款金額' => $localTransferAmount,
                ]);
            } elseif ($result === VendorTransferResultEnum::TIMEOUT) {
                $transactionLog->update([
                    'status' => TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value,
                    'remark' => 'timeout',
                ]);

                $this->logTransferLost($member, $vendor->id, $vendor->name, $localTransferAmount, $transactionLog, '三方逾時');
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('【轉入遊戲商】步驟3失敗：更新交易/退款處理發生例外', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        // Step 4: 查詢實際遊戲商餘額（遊戲商幣別，僅供前端顯示）
        // 未來若想將「查詢餘額」改為「查詢訂單狀態確認轉帳結果」，只需修改 Step 4
        // fallback：轉帳前餘額 + 本次轉入金額（遊戲商幣別）
        $postTransferVendorBalance = Common::numAdd($vendorBalance, $vendorDepositAmount);

        try {
            $skipGetBalanceVendorIds = [
                VendorIdEnum::SABA->value, // saba 體育不要查詢餘額，他有延遲
                VendorIdEnum::GreatWall->value, // great wall 有頻率限制
            ];
            if (!in_array($vendor->id, $skipGetBalanceVendorIds)) {
                $postTransferVendorBalance = $vendorDriver->getBalance();
            }
            Log::debug('【轉入遊戲商】步驟4完成：成功取得遊戲商餘額');
        } catch (Throwable $e) {
            Log::warning('【轉入遊戲商】步驟4：複查遊戲商餘額失敗，改用轉帳估算值', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'balance' => $memberWallets->wallet->balance,
            'vendor_balance' => $postTransferVendorBalance, // 遊戲商幣別
        ];
    }

    /**
     * 建立轉帳紀錄
     *
     * @param  TransactionEnum|null  $baseType  傳入基礎方向（FROM_VENDOR / TO_VENDOR）時，
     *                                          自動依會員錢包模式解析正確的 type / wallet_type_id；
     *                                          不傳則由呼叫端自行在 $data 內指定。
     */
    public function createTransactionLog(Member $member, AbstractTransferWallet $vendorDriver, array $data, ?TransactionEnum $baseType = null)
    {
        $isMultipleWallet = $member->agent->is_multi_wallet ?? false;

        // 若傳入 baseType，自動依據會員錢包模式解析 type / wallet_type_id，無需呼叫端手動指定
        if ($baseType !== null) {
            $data = array_merge($data, $this->resolveTransactionTypeData($member, $baseType));
        }

        $defaultData = [
            'agent_id' => $member->parent,
            'vendor_id' => 0,
            'order_id' => 0,
            'tax' => 0,
            'vendor_point' => 0,
            // 'rate' => 0,
            // 'before_balance' => $member->balance,
            'vendor_balance' => 0,
            'member_id' => $member->id,
            'status' => TransactionStatusEnum::STATUS_FAIL->value,
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'operator' => $member->username,
            'admin' => $member->id,
        ];

        $data = array_merge($defaultData, $data);
        $data['transaction_no'] ??= Common::getTransactionNo($isMultipleWallet);

        // 是否為活動錢包
        if ($member->is_event_wallet === true) {
            $WalletTransactionLog = WalletTransactionLog::create($data);

            // 只有真的參加 Event V2 活動才建立橋接記錄；新人專享挑戰沒有真實 event_v2_user_joins 可指
            if ($member->is_event_v2) {
                $vendorAccount = $vendorDriver->getAccount();

                EventV2WalletTransactionLog::create([
                    'wallet_transaction_log_id' => $WalletTransactionLog->id,
                    'vendor_id' => $data['vendor_id'],
                    'account_id' => $vendorAccount->id,
                    'account_username' => $vendorAccount->username,
                    'event_v2_user_join_id' => $member->eventV2Joins->first()->id,
                ]);
            }

            return $WalletTransactionLog;
        }

        // 代理是否為多錢包
        return $isMultipleWallet
            ? WalletTransactionLog::create($data)
            : TransactionLog::create($data);
    }

    /**
     * 依據會員錢包模式與基礎方向，解析正確的 type / wallet_type_id
     *
     * @return array{type: int, wallet_type_id?: int}
     */
    private function resolveTransactionTypeData(Member $member, TransactionEnum $baseType): array
    {
        if ($member->is_event_wallet) {
            return [
                'type' => match ($baseType) {
                    TransactionEnum::FROM_VENDOR => WalletTransactionEnum::EVENT_WALLET_FROM_VENDOR->value,
                    TransactionEnum::TO_VENDOR => WalletTransactionEnum::EVENT_WALLET_TO_VENDOR->value,
                    default => $baseType->value,
                },
                'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
            ];
        }

        if ($member->agent->is_multi_wallet) {
            return [
                'type' => match ($baseType) {
                    TransactionEnum::FROM_VENDOR => WalletTransactionEnum::GAME_WALLET_FROM_VENDOR->value,
                    TransactionEnum::TO_VENDOR => WalletTransactionEnum::GAME_WALLET_TO_VENDOR->value,
                    default => $baseType->value,
                },
                'wallet_type_id' => WalletEnum::CODE_GAME->value,
            ];
        }

        return ['type' => $baseType->value];
    }

    /**
     * 記錄轉丟事件
     *
     * 本地已扣款，但三方轉帳結果未知（不退款），統一由此方法輸出，
     * 確保每筆轉丟日誌格式一致、資訊完整，便於後續追查與告警。
     *
     * @param  string  $amount  轉丟金額（本地幣別，我方匯率）
     */
    private function logTransferLost(
        Member $member,
        int $vendorId,
        string $vendorName,
        string $amount,
        TransactionLog|WalletTransactionLog $transactionLog,
        string $reason,
    ): void {
        Log::alert(sprintf('[TRANSFER_LOST] %s 從 %s(#%d) 轉丟 %s（%s）', $member->username, $vendorName, $vendorId, $amount, $reason));
    }

    /**
     * 查詢用戶參與的活動關聯哪些遊戲商ID
     * TODO: Neil 說這功能沒在用, 可討論是否棄用
     *
     * @deprecated
     */
    public function getPromotionVendorIdsByMember(Member $member): array
    {
        return $member->promotionWallets()
            ->where('promotion_wallets.status', 1)
            ->pluck('event_promotions.vendor_id')
            ->toArray();
    }

    /**
     * TODO: Neil 說這功能沒在用, 可討論是否棄用
     *
     * @deprecated
     */
    public function getLastPromotionWalletLog(Member $member, int $eventId): ?PromotionWalletLog
    {
        return PromotionWalletLog::query()
            ->select(['vendor_id', 'type'])
            ->where('member_id', $member->id)
            ->where('event_id', $eventId)
            ->where('type', 1)
            ->where('status', 1000)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * 更新代理限額
     * TODO: Neil 說這功能沒在用, 可討論是否棄用
     *
     * @deprecated
     */
    private function updateTransferLimitByAgent(Agent $agent, $amount): void
    {
        $agentGroupIds = $agent->agentGroups->pluck('id')->toArray();

        $transferLimit = TransferLimit::query()
            ->lockForUpdate()
            ->whereIn('agent_group_id', $agentGroupIds)
            ->first();

        $transferLimit->limit = $transferLimit->limit + $amount;
        $transferLimit->save();
    }

    /**
     * 檢查是否符合遊戲商的最小轉帳額度要求
     *
     * @throws ApiException
     *
     * @deprecated 無呼叫點，未使用
     */
    private function validateWithdrawalAmount(VendorInfo $vendorInfo, string $withdrawalAmount): string
    {
        // 幣值轉換, 轉回我司匯率
        $rate = $vendorInfo->rate;
        $transferMinAmount = $vendorInfo->transfer_min_amount;
        $transferMinPoint = Common::numDiv($transferMinAmount, $rate);
        $withdrawalPoint = Common::numDiv($withdrawalAmount, $rate);

        // 轉帳金額小於最小轉帳金額
        if ($withdrawalPoint < $transferMinPoint) {
            throw new ApiException(__('error_message.1435'));
        }

        return $withdrawalPoint;
    }

    /**
     * 查詢遊戲商餘額
     *
     * @throws ApiException
     *
     * @deprecated 無呼叫點，未使用；餘額查詢邏輯已直接內聯於 transferToLocal / transferToVendor
     */
    private function handleVendorBalance($vendorDriver): string
    {
        try {
            $vendorBalance = $vendorDriver->getBalance();
        } catch (Throwable $e) {
            // 取得餘額失敗, 建立失敗紀錄, 暫時停止遊戲商轉帳
            VendorRequestFail::create([
                'vendor_id' => $vendorDriver->vendorId,
                'general_agent_id' => $vendorDriver->member->agent->general_agent_id ?? 0,
                'member_id' => $vendorDriver->member->id,
            ]);

            throw $e;
        }

        return $vendorBalance;
    }

    /**
     * 交易檢核碼 (應該沒在使用)
     *
     * @deprecated
     */
    private function getTransactionLogChecksum(array $data): string
    {
        $rule = [
            'member_id',
            'transaction_no',
            'vendor_id',
            'type',
            'point',
            'balance',
            // 'status', // 檢查碼不應使用可變動的欄位
            // 'remark', // 不然加個備註檢查碼就失效了
            // 'result',
        ];

        $str = '';
        foreach ($rule as $key) {
            $str .= $data[$key] ?? '';
        }

        return md5($str);
    }
}
