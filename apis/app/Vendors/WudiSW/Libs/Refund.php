<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
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

class Refund implements LibServiceInterface
{
    private TransferInfoData $dto;

    private VendorInfo $vendorInfo;

    private WudiswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings(TransferInfoData $dto, VendorInfo $vendorInfo, WudiswAccount $account): void
    {
        $this->dto = $dto;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        $refundAmount = Common::numDiv($this->dto->amount, $this->vendorInfo->rate);
        if (Common::numCompare($refundAmount, '0') < 0) {
            $refundAmount = Common::numMul($refundAmount, -1, 4);
        }

        $this->lockAndSetMember();
        $betRecord = $this->lockAndGetBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        [$walletRefund, $rollingChipRefund] = $this->calcRefunds($betRecord, $refundAmount);
        [, $rollingChipAfter] = $this->refundToRollingChipWallet($rollingChipRefund);
        [$walletBalanceBefore, $walletBalanceAfter] = $this->refundToWallet($walletRefund);

        // # Update bet record
        $infos = json_decode($betRecord->infos ?? '[]', true);
        $infos[] = $this->dto->infos;

        $betRecord->status = BetRecordStatusEnum::REFUND;
        $betRecord->payout_time = $this->dto->datetime;
        $betRecord->win_lose = Common::numAdd($refundAmount, $betRecord->win_lose);
        $betRecord->infos = json_encode($infos);
        $betRecord->save();

        $this->createTransactionLog($walletRefund, $walletBalanceBefore, $walletBalanceAfter);

        return Common::numMul(Common::numAdd($walletBalanceAfter, $rollingChipAfter), $this->vendorInfo->rate);
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Member not found', ErrorCodeEnum::SOMETHING_WRONG->value);
        }
    }

    private function lockAndGetBetRecord(): WudiswBetRecord
    {
        $betRecord = WudiswBetRecord::lockForUpdate()
            ->where('bet_id', $this->dto->orderId)
            ->first();

        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Place bet record not found', ErrorCodeEnum::TRANSACTION_NOT_EXIST->value);
        } elseif ($betRecord->status === BetRecordStatusEnum::REFUND) {
            throw new Exception('Bet record already refunded', ErrorCodeEnum::SUCCESS->value);
        } elseif ($betRecord->status !== BetRecordStatusEnum::START) {
            throw new Exception('Bet record already refunded', ErrorCodeEnum::TRANSACTION_SETTLED->value);
        }

        return $betRecord;
    }

    private function calcRefunds(WudiswBetRecord $betRecord, string $amount): array
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return ['0', '0'];
        }

        $walletPlaceBetAmount = Common::numSub($betRecord->bet, $betRecord->rolling_chips);
        $rollingChipPlaceBetAmount = $betRecord->rolling_chips;

        // Payback to wallet first
        $walletRefund = '0';
        if (Common::numCompare($walletPlaceBetAmount, $amount) >= 0) {
            $walletRefund = $amount;
            $amount = '0';
        } else {
            $walletRefund = $walletPlaceBetAmount;
            $amount = Common::numSub($amount, $walletPlaceBetAmount);
        }

        // Payback to rolling chips
        $rollingChipRefund = '0';
        if (Common::numCompare($rollingChipPlaceBetAmount, $amount) >= 0) {
            $rollingChipRefund = $amount;
            $amount = '0';
        } else {
            $rollingChipRefund = $rollingChipPlaceBetAmount;
            $amount = Common::numSub($amount, $rollingChipPlaceBetAmount);
        }

        if (Common::numCompare($amount, '0') > 0) {
            throw new Exception(
                "Payback amount {$amount} is greater than wallet refund and rolling chip refund",
                ErrorCodeEnum::SOMETHING_WRONG->value
            );
        }

        return [$walletRefund, $rollingChipRefund];
    }

    private function refundToWallet(string $amount): array
    {
        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $amount);
        if (Common::numCompare($amount, '0') > 0) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        return [$walletBalanceBefore, $walletBalanceAfter];
    }

    private function refundToRollingChipWallet(string $amount): array
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return ['0', '0'];
        }

        $rollingChipWallet = $this->walletsDTO->rollingChipWallet;
        if (!is_object($rollingChipWallet)) {
            throw new Exception(
                'Rolling chip wallet not found',
                ErrorCodeEnum::SOMETHING_WRONG->value
            );
        }

        $rollingChipService = new RollingChipService($this->member, $rollingChipWallet, $this->vendorInfo->vendor_id);
        $result = $rollingChipService->add($amount, RollingChipLogTypeEnum::BET_CANCEL);

        // [before, after]
        return [$result->beforeBalance, $result->afterBalance];
    }

    private function createTransactionLog(string $amount, string $balanceBefore, string $balanceAfter): void
    {
        if (Common::numCompare($amount, '0') <= 0) {
            return;
        }

        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $balanceBefore, $balanceAfter);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'RefundBet, Transaction log failed, Id is empty',
                ErrorCodeEnum::SOMETHING_WRONG->value
            );
        }
    }
}
