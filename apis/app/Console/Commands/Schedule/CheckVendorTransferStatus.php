<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\EventV2\EventV2RecycleStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\EventV2Recycle;
use App\Models\EventV2UserJoin;
use App\Models\EventV2WalletTransactionLog;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 排程：對「轉入遊戲商逾時、結果未知」的單，向三方查證實際轉帳狀態並結清。
 *
 * 註冊於 routes/console.php：每分鐘執行、onOneServer、withoutOverlapping(3600)。
 * 手動：php artisan check_vendor_transfer_status [--vendor-id=]
 *
 * 一筆轉入單要被本排程撈出處理，須「同時」滿足下列條件：
 *   1. vendor 已列入 $checkVendorIds（本排程實際會檢查的遊戲商清單）。
 *      --vendor-id 也會與此清單取交集，故未列入者，連手動指定都不會被處理（見 handle()）。
 *   2. 對應 driver 已實作 checkTransferStatus()；AbstractTransferWallet 預設拋例外。
 *   3. 狀態為 TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED（8888，逾時/結果未知）。
 *   4. type 為轉入類：一般會員 TransactionEnum::TO_VENDOR；多錢包/活動錢包
 *      WalletTransactionEnum::GAME_WALLET_TO_VENDOR 或 EVENT_WALLET_TO_VENDOR。
 *   5. created_at 落在「1 小時前 ～ 1 分鐘前」；超過 1 小時即不再自動處理（落人工）。
 *   6. member 與 vendor 關聯皆存在，否則該筆拋例外跳過。
 *
 * 命中後依 checkTransferStatus() 回傳處置：
 *   SUCCESS → 樂觀鎖標記成功；FAILED → 退款（活動錢包改走回收）；TIMEOUT → 不動、待下輪。
 *
 * ⚠️ 將某 vendor 加入 $checkVendorIds 前的前提：該 vendor 的 checkTransferStatus()
 *    必須能回傳「確定失敗」。若其三方狀態查詢無「失敗」語意（例：PP 僅 Success/Not found，
 *    Not found=未處理非失敗），不可加入，否則 FAILED→退款分支會誤退造成雙重入帳。
 *    這類 vendor 要能加入，前提是先實作「以相同交易號冪等重送轉帳」並把退款判定改綁重送
 *    結果（重送回「轉帳失敗」才退款），而不是沿用「狀態查詢＝FAILED 就退款」；PP 的具體政策
 *    見 App\Vendors\Pp\Driver 的 class docblock。
 */
class CheckVendorTransferStatus extends Command
{
    /**
     * php artisan check_vendor_transfer_status
     *
     * @var string
     */
    protected $signature = 'check_vendor_transfer_status {--vendor-id= : 指定要處理的遊戲商 ID}';

    protected $description = '檢查轉帳狀態並退款, (內部有個陣列指定哪些遊戲商需要檢查, 不會全跑)';

    protected array $checkVendorIds = [
        VendorIdEnum::CT->value,
        VendorIdEnum::DG->value,
        VendorIdEnum::SABA->value,
        VendorIdEnum::AES->value,
    ];

    public function handle(): void
    {
        $vendorId = $this->option('vendor-id');
        $vendorIds = $vendorId ? [(int) $vendorId] : $this->checkVendorIds;

        // 過濾出需要檢查的遊戲商
        $vendorIds = array_intersect($vendorIds, $this->checkVendorIds);

        // 查詢時間範圍：1分鐘前到1小時前
        $endTime = Carbon::now()->subMinute();
        $startTime = Carbon::now()->subHour();

        $this->info("查詢時間範圍: {$startTime->toDateTimeString()} ~ {$endTime->toDateTimeString()}");
        $this->info('需要檢查的遊戲商 ID: ' . implode(', ', $vendorIds));

        // 查詢 TransactionLog (單錢包)
        $transactionLogs = TransactionLog::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('type', TransactionEnum::TO_VENDOR->value)
            ->where('status', TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->with(['member', 'vendor'])
            ->get();

        // 查詢 WalletTransactionLog (多錢包 + 活動錢包)
        $walletTransactionLogs = WalletTransactionLog::query()
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('type', [
                WalletTransactionEnum::GAME_WALLET_TO_VENDOR->value,
                WalletTransactionEnum::EVENT_WALLET_TO_VENDOR->value,
            ])
            ->where('status', TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->with(['member', 'vendor'])
            ->get();

        $totalCount = $transactionLogs->count() + $walletTransactionLogs->count();
        $this->info("找到 {$totalCount} 筆待檢查的訂單");

        if ($totalCount === 0) {
            return;
        }

        $successCount = 0;
        $failedCount = 0;
        $timeoutCount = 0;
        $errorCount = 0;

        // 合併處理所有訂單
        $allLogs = $transactionLogs->toBase()->merge($walletTransactionLogs->toBase());

        foreach ($allLogs as $log) {
            try {
                $result = $this->processTransactionLog($log);
                match ($result) {
                    VendorTransferResultEnum::SUCCESS => $successCount++,
                    VendorTransferResultEnum::FAILED => $failedCount++,
                    VendorTransferResultEnum::TIMEOUT => $timeoutCount++,
                };
            } catch (Throwable $e) {
                $errorCount++;
                $logType = $log instanceof WalletTransactionLog ? 'WalletTransactionLog' : 'TransactionLog';
                $this->error("處理 {$logType} ID {$log->id} 時發生錯誤: {$e->getMessage()}");
                Log::error("[CheckVendorTransferStatus] 處理 {$logType} 錯誤", [
                    'log_id' => $log->id,
                    'transaction_no' => $log->transaction_no,
                    'member_id' => $log->member_id,
                    'vendor_id' => $log->vendor_id,
                    'error' => $e,
                ]);
            }
        }

        $this->info("處理完成 - 成功: {$successCount}, 失敗: {$failedCount}, 逾時: {$timeoutCount}, 錯誤: {$errorCount}");
    }

    /**
     * 處理交易紀錄（TransactionLog 或 WalletTransactionLog）
     */
    private function processTransactionLog(TransactionLog|WalletTransactionLog $log): VendorTransferResultEnum
    {
        $member = $log->member;
        $vendor = $log->vendor;

        if (!$member || !$vendor) {
            throw new \Exception("會員或遊戲商不存在 - member_id: {$log->member_id}, vendor_id: {$log->vendor_id}");
        }

        $vendorService = App::make(VendorService::class);
        $vendorDriver = $vendorService->getDriver($vendor->id, $member);

        $this->info("檢查訂單: {$log->transaction_no} (Member: {$member->id}, Vendor: {$vendor->name})");

        // 呼叫三方確認轉帳狀態
        $result = $vendorDriver->checkTransferStatus($log->transaction_no);

        // 根據結果更新訂單狀態
        DB::beginTransaction();
        try {
            if ($result === VendorTransferResultEnum::SUCCESS) {
                // 成功：使用樂觀鎖更新為成功狀態
                $modelClass = $log instanceof WalletTransactionLog ? WalletTransactionLog::class : TransactionLog::class;
                $updated = $modelClass::query()
                    ->where('id', $log->id)
                    ->where('status', TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value)
                    ->update([
                        'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                        'remark' => '【檢查轉帳狀態】三方確認轉帳成功',
                    ]);

                if ($updated > 0) {
                    $this->info('  ✓ 轉帳成功');
                } else {
                    $this->warn('  ⚠ 訂單狀態已被修改，跳過更新');
                }
            } elseif ($result === VendorTransferResultEnum::FAILED) {
                // 失敗：退款給玩家
                $this->refundTransactionLog($log, $member, $vendorDriver);
            } elseif ($result === VendorTransferResultEnum::TIMEOUT) {
                $this->warn('  ⏱ 轉帳狀態仍為逾時');
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * 退款交易紀錄（TransactionLog 或 WalletTransactionLog）
     */
    private function refundTransactionLog(TransactionLog|WalletTransactionLog $log, Member $member, $vendorDriver): void
    {
        // 使用樂觀鎖更新原本的交易紀錄為已退回平台餘額
        $modelClass = $log instanceof WalletTransactionLog ? WalletTransactionLog::class : TransactionLog::class;
        $updated = $modelClass::query()
            ->where('id', $log->id)
            ->where('status', TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value)
            ->update([
                'status' => TransactionStatusEnum::STATUS_TO_VENDOR_FAILED_REFUND->value,
            ]);

        if ($updated === 0) {
            // 狀態已被其他進程修改，跳過處理
            $logType = $log instanceof WalletTransactionLog ? 'wallet_transaction_log_id' : 'transaction_log_id';
            Log::warning('[CheckVendorTransferStatus] 訂單狀態已被修改，跳過退款', [
                $logType => $log->id,
                'transaction_no' => $log->transaction_no,
            ]);
            throw new \Exception('訂單狀態已被修改，無法退款');
        }

        /** @var MemberTransferService $memberTransferService */
        $memberTransferService = App::make(MemberTransferService::class);

        // 鎖定並取得會員錢包
        if ($modelClass === TransactionLog::class) {
            $wallet = Member::lockForUpdate()->find($member->id);
            $memberBeforeBalance = $wallet->balance;
        } elseif ($modelClass === WalletTransactionLog::class) {
            if ($log->wallet_type_id === WalletEnum::CODE_GAME->value) {
                $wallet = MemberWallet::lockForUpdate()
                    ->where('member_id', $member->id)
                    ->where('wallet_type_id', WalletEnum::CODE_GAME->value)
                    ->first();
            } elseif ($log->wallet_type_id === WalletEnum::CODE_EVENT_WALLET->value) {
                $wallet = MemberWallet::lockForUpdate()
                    ->where('member_id', $member->id)
                    ->where('wallet_type_id', WalletEnum::CODE_EVENT_WALLET->value)
                    ->first();
            } else {
                throw new \Exception('錢包類型不存在');
            }
        }
        $memberBeforeBalance = $wallet->balance;
        $refundAmount = $log->point;
        $memberAfterBalance = Common::numAdd($memberBeforeBalance, $refundAmount, 4);

        // 檢查活動v2 是否需要回收餘額, 只有參加活動的時候不需要回收.
        if (
            $modelClass === WalletTransactionLog::class
            && $log->wallet_type_id === WalletEnum::CODE_EVENT_WALLET->value
        ) {
            $needRecycle = true;
            $eventV2WalletTransactionLog = EventV2WalletTransactionLog::query()
                ->where('wallet_transaction_log_id', $log->id)
                ->first();
            if ($eventV2WalletTransactionLog) {
                $eventV2UserJoin = EventV2UserJoin::query()
                    ->where('id', $eventV2WalletTransactionLog->event_v2_user_join_id)
                    ->first();
                if ($eventV2UserJoin && $eventV2UserJoin->status === EventV2UserJoinStatusEnum::JOIN->value) {
                    $needRecycle = false;
                }
            }

            if ($needRecycle && isset($eventV2UserJoin)) {
                // 新增回收紀錄, 直接返回, 不需要更新會員餘額和建立退款交易紀錄
                $eventV2Recycle = EventV2Recycle::create([
                    'event_v2_user_join_id' => $eventV2UserJoin->id,
                    'final_balance' => 0,
                    'withdraw' => 0,
                    'recycle_amount' => $log->point,
                    'status' => EventV2RecycleStatusEnum::TO_VENDOR_FAILED_REFUND->value,
                ]);
                // status 更新為失敗已回收
                $modelClass::query()
                    ->where('id', $log->id)
                    ->where('status', TransactionStatusEnum::STATUS_TO_VENDOR_FAILED_REFUND->value)
                    ->update([
                        'status' => TransactionStatusEnum::STATUS_TO_VENDOR_FAILED_RECYCLE->value,
                    ]);

                $this->info('  ✗ 轉帳失敗，已回收');
                Log::info('[CheckVendorTransferStatus] 新增回收紀錄', [
                    'event_v2_recycle_id' => $eventV2Recycle->id,
                    'recycle_amount' => $log->point,
                    'status' => EventV2RecycleStatusEnum::TO_VENDOR_FAILED_REFUND->value,
                ]);

                return;
            }
        }

        // 更新會員餘額
        $wallet->update([
            'balance' => $memberAfterBalance,
        ]);

        // 建立退款交易紀錄
        $refundTransactionLogData = [
            'transaction_no' => $vendorDriver->getTransactionNo(TransactionEnum::TO_VENDOR),
            'vendor_id' => $log->vendor_id,
            'type' => TransactionEnum::TO_VENDOR_FAILED_REFUND->value, // createTransactionLog 會根據會員類型自動處理
            'point' => $refundAmount,
            'vendor_point' => $log->vendor_point,
            'rate' => $log->rate,
            'before_balance' => $memberBeforeBalance,
            'balance' => $memberAfterBalance,
            'vendor_balance' => $log->vendor_balance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '【檢查轉帳狀態】三方確認轉帳失敗，已退回本地餘額，ref_id:' . $log->id,
        ];

        // 如果有 wallet_type_id，需要保留（活動錢包或多錢包）
        if ($log instanceof WalletTransactionLog && isset($log->wallet_type_id)) {
            $refundTransactionLogData['wallet_type_id'] = $log->wallet_type_id;
        }

        $refundLog = $memberTransferService->createTransactionLog($member, $vendorDriver, $refundTransactionLogData);

        $this->info('  ✗ 轉帳失敗，已退款');
        Log::info('[CheckVendorTransferStatus] 退款成功', [
            'member_id' => $member->id,
            'vendor_id' => $log->vendor_id,
            '原交易_no' => $log->transaction_no,
            '原交易_log_id' => $log->id,
            '退款交易_no' => $refundLog->transaction_no ?? null,
            '退款交易_log_id' => $refundLog->id ?? null,
            '退款金額' => $refundAmount,
        ]);
    }
}
