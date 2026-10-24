<?php

declare(strict_types=1);

namespace App\Vendors;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Helpers\Common;
use App\Models\MemberAccessToken;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Illuminate\Support\Facades\Log;

abstract class AbstractTransferWallet extends BaseAbstractVendor
{
    /**
     * 查詢餘額, 未轉換為本地錢包匯率
     */
    abstract public function getBalance(): string;

    /**
     * 取得遊戲網址
     * ( VendorGameUrlResultData.pc + VendorGameUrlResultData.mobile)
     * ( VendorGameUrlResultData.app_infos )
     * 以上兩組資料至少要有一組不為空 (三者不能同時為空)，會在 GameController::getUrl 中檢查
     */
    abstract public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData;

    /**
     * 玩家餘額轉出到遊戲商
     *
     * 1. 如果 無法完全確認結果，請回傳 VendorTransferResultEnum::TIMEOUT
     *    ex: response empty, response data status code not found 等無法知道對方結果的情況
     * 2. 如果 失敗，請回傳 VendorTransferResultEnum::FAILED
     * 3. 如果 成功，請回傳 VendorTransferResultEnum::SUCCESS
     */
    abstract public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum;

    /**
     * 從遊戲商轉回玩家餘額
     *
     * 1. 如果 無法完全確認結果，請回傳 VendorTransferResultEnum::TIMEOUT
     *    ex: response empty, response data status code not found 等無法知道對方結果的情況
     * 2. 如果 失敗，請回傳 VendorTransferResultEnum::FAILED
     * 3. 如果 成功，請回傳 VendorTransferResultEnum::SUCCESS
     */
    abstract public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum;

    /**
     * 檢查轉帳狀態
     *
     * @throws \Exception 如果出現要檢查的情況卻沒有 override 代表有問題
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        throw new \Exception(static::class . '::checkTransferStatus not implemented');
    }

    /**
     * 取得交易紀錄
     */
    public function getTransactionLog(string $transactionNo): TransactionLog|WalletTransactionLog
    {
        $isMultipleWallet = $this->member->agent->is_multi_wallet ?? false;

        $filter = [
            'transaction_no' => $transactionNo,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
        ];

        if ($isMultipleWallet) {
            $log = WalletTransactionLog::where($filter)->first();
        } else {
            $log = TransactionLog::where($filter)->first();
        }

        if (!$log) {
            $errorMessage = 'Transaction log not found, ' . $transactionNo;
            Log::error($errorMessage, $filter);
            throw new \Exception($errorMessage);
        }

        return $log;
    }

    /**
     * Auto generate transaction no, 遊戲商如果有指定格式, 請 override 這個方法
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        if ($this->member->is_event_wallet || $this->agent->is_multi_wallet) {
            $transactionNo = Common::getTransactionNo(true);
        } else {
            $transactionNo = Common::getTransactionNo(false);
        }

        return $transactionNo;
    }

    public function getMemberAccessToken(): string
    {
        return MemberAccessToken:[REDACTED]
            ->where('member_id', $this->member->id)
            ->orderBy('id', 'DESC')
            ->first()
            ->token ?? '';
    }
}
