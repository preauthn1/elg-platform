<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\TfgamingswAccount;
use App\Vendors\Models\TfgamingswBetRecord;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\BetRecordStatusEnum;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use Exception;
use Illuminate\Database\QueryException;

class Bet implements LibServiceInterface
{
    private TransferInfoData $dto;

    private VendorInfo $vendorInfo;

    private TfgamingswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings(
        TransferInfoData $transferInfoDTO,
        VendorInfo $vendorInfo,
        TfgamingswAccount $account
    ): void {
        $this->dto = $transferInfoDTO;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        if (!$this->vendorInfo->status) {
            throw new Exception('Vendor Info closed', ErrorCodeEnum::INVALID_OPERATOR->value);
        }

        $this->lockAndSetMember();

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, 'TP01');
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::INVALID_MEMBER_STATE->value);
        }

        $betRecord = $this->createBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        $amount = Common::numDiv($this->dto->amount, $this->vendorInfo->rate);
        if (Common::numCompare($amount, 0) < 0) { // should be positive when bet
            $amount = Common::numMul($amount, -1);
        } else {
            throw new Exception('Amount should be positive', ErrorCodeEnum::UNDEFINED->value);
        }

        $this->checkBalanceEnough($amount);

        $rollingChipPaidAmount = $this->payRollingChip($amount);
        $walletPaidAmount = Common::numSub($amount, $rollingChipPaidAmount);
        $walletBalanceAfter = $this->payWallet($walletPaidAmount);

        // Log
        // # Update bet record
        $betRecord->update([
            'bet' => $amount,
            'valid_bet' => $amount,
            'win_lose' => Common::numMul($amount, -1),
            'rolling_chips' => $rollingChipPaidAmount,
        ]);

        $balance = $walletBalanceAfter;

        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $balance = Common::numAdd($balance, $this->walletsDTO->rollingChipWallet->balance);
        }

        return Common::numMul($balance, $this->vendorInfo->rate, 4);
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Account not found', ErrorCodeEnum::INVALID_AUTH->value);
        }
    }

    /**
     * @throws QueryException, duplicate entry with code 1062.
     * @throws Exception Bet record failed.
     */
    private function createBetRecord(): TfgamingswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = TfgamingswBetRecord::create([
            'bet_id' => $this->dto->ticketNum,
            'username' => $this->dto->username,
            'game_code' => 'TP01',
            'status' => BetRecordStatusEnum::NO_RESULT,
            'infos' => json_encode($this->dto->infos),
            'type' => $this->dto->description,

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->settleTime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::UNDEFINED->value);
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

    /**
     * @param  float  $amount
     *
     * @throws Exception Transaction log failed.
     */
    private function logTransactionLog(string $amount, string $before, string $after): void
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return;
        }

        $amount = Common::numMul($amount, -1);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Bet, Transaction log failed, Id is empty',
                ErrorCodeEnum::UNDEFINED->value
            );
        }
    }
}
