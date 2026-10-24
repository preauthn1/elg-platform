<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\Models\WudiswBetRecord;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Enums\BetRecordStatusEnum;
use App\Vendors\WudiSW\Enums\ErrorCodeEnum;
use Exception;
use Illuminate\Database\QueryException;

class Start implements LibServiceInterface
{
    private TransferInfoData $dto;

    private VendorInfo $vendorInfo;

    private WudiswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings(
        TransferInfoData $transferInfoDTO,
        VendorInfo $vendorInfo,
        WudiswAccount $account
    ): void {
        $this->dto = $transferInfoDTO;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        $amount = Common::numDiv($this->dto->amount, $this->vendorInfo->rate, 4);
        if (Common::numCompare($amount, '0') < 0) {
            $amount = Common::numMul($amount, -1, 4);
        }

        if (!$this->vendorInfo->status) {
            throw new Exception('Vendor Info closed', ErrorCodeEnum::SOMETHING_WRONG->value);
        }

        $this->lockAndSetMember();
        $betRecord = $this->createBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $this->checkBalanceEnough($amount);

        $rollingChipPaidAmount = $this->payRollingChip($amount);
        $walletPaidAmount = Common::numSub($amount, $rollingChipPaidAmount, 4);
        $walletBalanceAfter = $this->payWallet($walletPaidAmount);

        // Log
        // # Update bet record
        $betRecord->update([
            'bet' => $amount,
            'valid_bet' => $amount,
            'win_lose' => Common::numMul($amount, -1, 4),
            'rolling_chips' => $rollingChipPaidAmount,
        ]);

        $balance = $walletBalanceAfter;

        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $balance = Common::numAdd($balance, $this->walletsDTO->rollingChipWallet->balance, 4);
        }

        return Common::numMul($balance, $this->vendorInfo->rate);
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Account not found', ErrorCodeEnum::SOMETHING_WRONG->value);
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->dto->gameCode); // only one game
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::SOMETHING_WRONG->value);
        }
    }

    /**
     * @throws QueryException, duplicate entry with code 1062.
     * @throws Exception Bet record failed.
     */
    private function createBetRecord(): WudiswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = WudiswBetRecord::create([
            'bet_id' => $this->dto->orderId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameCode,
            'status' => BetRecordStatusEnum::START,
            'infos' => json_encode([$this->dto->infos]),

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::SOMETHING_WRONG->value);
        }

        return $betRecord;
    }

    private function checkBalanceEnough(string $amount): void
    {
        $oldTotalBalance = $this->walletsDTO->wallet->balance;
        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $oldTotalBalance = Common::numAdd($oldTotalBalance, $this->walletsDTO->rollingChipWallet->balance);
        }

        if (Common::numCompare(Common::numSub($oldTotalBalance, $amount), '0') < 0) {
            throw new Exception(
                'Wallet balance not enough',
                ErrorCodeEnum::INSUFFICIENT_BALANCE->value
            );
        }
    }

    private function payRollingChip(string $amount): string
    {
        $rollingChipWallet = $this->walletsDTO->rollingChipWallet;
        if (!is_object($rollingChipWallet)) {
            return '0';
        }

        $rollingChipService = new RollingChipService($this->member, $rollingChipWallet, $this->vendorInfo->vendor_id);
        $result = $rollingChipService->sub($amount, RollingChipLogTypeEnum::BET_SETTLE);

        return Common::numSub($result->beforeBalance, $result->afterBalance);
    }

    private function payWallet(string $amount): string
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return $this->walletsDTO->wallet->balance;
        }

        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numSub($walletBalanceBefore, $amount);
        if (Common::numCompare($walletBalanceAfter, '0') < 0) { // Double check
            throw new Exception(
                'Wallet balance not enough',
                ErrorCodeEnum::INSUFFICIENT_BALANCE->value
            );
        }

        $this->walletsDTO->wallet->decrement('balance', $amount);

        $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        return $walletBalanceAfter;
    }

    private function logTransactionLog(string $amount, string $before, string $after): void
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return;
        }

        $amount = Common::numMul($amount, -1, 4);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Bet, Transaction log failed, Id is empty',
                ErrorCodeEnum::SOMETHING_WRONG->value
            );
        }
    }
}
