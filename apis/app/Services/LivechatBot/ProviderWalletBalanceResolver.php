<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Helpers\Common;
use App\Models\Member;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Livechat Bot 共用：取得會員最後一次轉入遊戲商的即時餘額
 *
 * 抽離自 GetBalanceService，供 GetBalanceService 與 GetWithdrawBalanceService 共用。
 */
class ProviderWalletBalanceResolver
{
    public function __construct(
        protected MemberTransferService $memberTransferService,
        protected VendorService $vendorService,
    ) {}

    /**
     * 取得會員最後一次轉入 vendor 的即時餘額。
     *
     * 任一失敗情境（無轉入紀錄、driver 不支援轉帳錢包、HTTP 錯誤等）一律回傳 '0.00'。
     */
    public function resolve(Member $member): string
    {
        try {
            $lastTransferLog = $this->memberTransferService->getLastTransferOutTransactionLog($member);

            if (!$lastTransferLog) {
                return Common::numAdd('0', '0', 2);
            }

            $driver = $this->vendorService->getDriver($lastTransferLog->vendor_id, $member);

            if ($driver instanceof AbstractTransferWallet) {
                return Common::numAdd('0', (string) $driver->getBalance(), 2);
            }

            return Common::numAdd('0', '0', 2);
        } catch (Throwable $e) {
            Log::error('LivechatBot provider wallet balance resolve failed', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return Common::numAdd('0', '0', 2);
        }
    }
}
