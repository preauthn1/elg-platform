<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Models\VendorInfo;

/**
 * 遊戲商「預建帳號」能力契約
 *
 * 定義 VendorAccountPoolService 補充帳號池所需的全部方法；實作本介面即可被
 * create_vendor_account --vendor-id 手動觸發補建，要加入自動排程
 * 請實作 ShouldScheduleAccountCreation（繼承本契約的純 marker）。
 *
 * @knowledge rules/vendor-account-pool
 */
interface SupportsAccountCreation
{
    /**
     * 呼叫三方 API 建立單一遊戲帳號並寫入帳號表
     *
     * 🔴 **建號一律兩段式 member_id**：插入當下即以 -999999999 佔位，三方確實建成才翻 0
     * 入池——杜絕「我方已寫入但三方還沒建成」的帳號被玩家搶先綁定。未綁定帳號的口徑是
     * `member_id = 0` 且 status 啟用，佔位列因此天然不進水位計算。
     *
     * 失敗以 result 的 status = false 回報或拋例外，兩者皆由
     * VendorAccountPoolService 記錄後繼續補下一個帳號（單商失敗須隔離、不中斷其他商）。
     * ⚠️ 乾跑回滾僅及於我方 DB，已送出三方的建號請求不會撤銷。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData;

    /**
     * Account Model 類別名稱，例如 KissH5SWAccount::class
     */
    public function getAccountModel(): string;

    /**
     * DB 上儲存帳號 username 的完整 prefix（流水號解析用）
     *
     * 多數 vendor = vendor_info.account_prefix；JokerBaccaratSW 這類
     * portal 會自動加 merchant ID 的需覆寫。
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string;
}
