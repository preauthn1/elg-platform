<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\RollingChipLog;

/**
 * 禮碼（＝泥碼＝滾碼，同一件事的三種叫法，搜尋時三個詞都要試）錢包的加減與流水。
 *
 * 禮碼餘額就是 member_wallets 裡 WalletEnum::CODE_ROLLING_CHIP 的那一列，
 * **禁止另立禮碼欄位或禮碼專用餘額表**；每次加減必落一列 rolling_chip_logs。
 *
 * 接入現況（新增發放或消耗點一律走本 Service，不再新增直寫）：
 * - 單一錢包投注路徑必經本 Service（含交易層級檢查與扣到 0 保護）；SW 扣帳底座的
 *   changeBalance() 已在算真金變動前自動結算禮碼，Controller 不需自行扣。
 * - 活動／VIP／簽到側為**存量直寫**（自行 lockForUpdate 更新並自建 log）。
 *
 * ⚠️ 這兩張表都不能拿來替代本表的即時流水：注單上的 rolling_chip 欄位由各 BR 表宣告，
 * 小時級的 summary_rolling_chip_records 由 SBR 結算刪除重建。
 */
class RollingChipService
{
    private array $optionalData = [];

    public function __construct(
        private Member $member,
        private ?MemberWallet $wallet = null,
        private int $vendorId = 0
    ) {
        if (is_null($this->wallet)) {
            $this->wallet = (new WalletService)->getLockedMemberWallet($this->member->id, WalletEnum::CODE_ROLLING_CHIP);
        }
    }

    public function setVendorId(int $vendorId): static
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    public function setOptionalData(array $optionalData): static
    {
        $this->optionalData = $optionalData;

        return $this;
    }

    public function add(float|string $amount, RollingChipLogTypeEnum $type, string $remark = ''): BalanceUpdateResultDTO
    {
        if (Common::numCompare($amount, '0') < 0) {
            $amount = Common::numMul($amount, -1);
        }

        return $this->updateBalance($amount, $type, $remark);
    }

    public function sub(float|string $amount, RollingChipLogTypeEnum $type, string $remark = ''): BalanceUpdateResultDTO
    {
        if (Common::numCompare($amount, '0') > 0) {
            $amount = Common::numMul($amount, -1);
        }

        return $this->updateBalance($amount, $type, $remark);
    }

    public function updateBalance(float|string $amount, RollingChipLogTypeEnum $type, string $remark = ''): BalanceUpdateResultDTO
    {
        if (!\DB::transactionLevel()) {
            throw new \Exception('Should be in transaction');
        }

        if ($this->wallet->wallet_type_id !== WalletEnum::CODE_ROLLING_CHIP) {
            throw new \Exception(
                "Invalid wallet type {$this->wallet->wallet_type_id->value}, should be " . WalletEnum::CODE_ROLLING_CHIP->value
            );
        }

        // 參加活動v2/使用活動錢包不會動到禮碼
        if ($this->member->is_event_wallet === true) {
            return new BalanceUpdateResultDTO('0', '0', null);
        }

        if (Common::numCompare($amount, '0') === 0) {
            return new BalanceUpdateResultDTO($this->wallet->balance, $this->wallet->balance, null);
        }

        $beforeBalance = $this->wallet->balance;
        $afterBalance = Common::numAdd($this->wallet->balance, $amount);
        if (Common::numCompare($afterBalance, '0') >= 0) {
            $this->wallet->increment('balance', $amount);
        } else {
            $afterBalance = '0';
            $this->wallet->update(['balance' => 0]);
            $amount = Common::numMul($beforeBalance, -1); // All paid, and amount is negative
        }

        $data = array_merge([
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendorId,
            'type' => $type,
            'amount' => Common::numCompare($amount, '0') < 0 ? Common::numMul($amount, -1) : $amount,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => 1000,
            'remark' => $remark,
            'admin' => 0,
        ], $this->optionalData);

        $result = RollingChipLog::create($data);
        if (!$result) {
            throw new \Exception('Failed to write rolling chips log');
        }

        return new BalanceUpdateResultDTO((string) $beforeBalance, (string) $afterBalance, $result);
    }
}

class BalanceUpdateResultDTO
{
    public function __construct(
        public readonly string $beforeBalance,
        public readonly string $afterBalance,
        public readonly ?RollingChipLog $log = null,
    ) {}
}
