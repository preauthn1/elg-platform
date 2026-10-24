<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
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

class Rollback implements LibServiceInterface
{
    private TransferInfoData $dto;

    private VendorInfo $vendorInfo;

    private TfgamingswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings(TransferInfoData $dto, VendorInfo $vendorInfo, TfgamingswAccount $account): void
    {
        $this->dto = $dto;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        $refundAmount = (float) Common::numDiv($this->dto->amount, $this->vendorInfo->rate);
        if (Common::numCompare($refundAmount, 0) < 0) {
            $refundAmount = (float) Common::numMul($refundAmount, -1);
        }

        $this->lockAndSetMember();
        $betRecord = $this->lockAndGetBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        [$walletRefund, $rollingChipRefund] = $this->calcRefunds($betRecord, $refundAmount);
        [$rollingChipBefore, $rollingChipAfter] = $this->refundToRollingChipWallet($rollingChipRefund);
        [$walletBalanceBefore, $walletBalanceAfter] = $this->refundToWallet($walletRefund);

        $newInfos = [
            json_decode($betRecord->infos ?? '[]', true),
            $this->dto->infos,
        ];

        // # Update bet record
        $betRecord->status = BetRecordStatusEnum::CANCEL;
        $betRecord->win_lose = Common::numAdd($refundAmount, $betRecord->win_lose);
        $betRecord->type = $this->dto->description;
        $betRecord->payout_time = $this->dto->datetime;
        $betRecord->infos = json_encode($newInfos);
        $betRecord->save();

        $this->createTransactionLog($walletRefund, $walletBalanceBefore, $walletBalanceAfter);

        return Common::numMul(Common::numAdd($walletBalanceAfter, $rollingChipAfter), $this->vendorInfo->rate, 4);
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Member not found', ErrorCodeEnum::INVALID_MEMBER_STATE->value);
        }
    }

    private function lockAndGetBetRecord(): TfgamingswBetRecord
    {
        $betRecord = TfgamingswBetRecord::lockForUpdate()
            ->where('bet_id', $this->dto->ticketId)
            ->first();
        if (!is_object($betRecord)) {
            throw new Exception(
                "Ticket id {$this->dto->ticketId} not found",
                ErrorCodeEnum::UNDEFINED->value
            );
        } elseif ($betRecord->status !== BetRecordStatusEnum::NO_RESULT) {
            throw new Exception(
                "Ticket id {$this->dto->ticketId} already has result",
                ErrorCodeEnum::UNDEFINED->value
            );
        }

        return $betRecord;
    }

    private function calcRefunds(TfgamingswBetRecord $betRecord, float $amount): array
    {
        if ($amount <= 0) {
            return [0, 0];
        }

        $walletPlaceBetAmount = Common::numSub($betRecord->bet, $betRecord->rolling_chips);
        $rollingChipPlaceBetAmount = $betRecord->rolling_chips;

        // Payback to wallet first
        $walletRefund = 0;
        if ($walletPlaceBetAmount >= $amount) {
            $walletRefund = $amount;
            $amount = 0;
        } else {
            $walletRefund = $walletPlaceBetAmount;
            $amount = Common::numSub($amount, $walletPlaceBetAmount);
        }

        // Payback to rolling chips
        $rollingChipRefund = 0;
        if ($rollingChipPlaceBetAmount >= $amount) {
            $rollingChipRefund = $amount;
            $amount = 0;
        } else {
            $rollingChipRefund = $rollingChipPlaceBetAmount;
            $amount = Common::numSub($amount, $rollingChipPlaceBetAmount);
        }

        if ($amount > 0) {
            throw new Exception(
                "Payback amount {$amount} is greater than wallet refund and rolling chip refund",
                ErrorCodeEnum::INVALID_AMOUNT->value
            );
        }

        return [$walletRefund, $rollingChipRefund];
    }

    private function refundToWallet(float $amount): array
    {
        $walletBalanceBefore = (float) $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = (float) Common::numAdd($walletBalanceBefore, $amount);
        if ($amount > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        return [$walletBalanceBefore, $walletBalanceAfter];
    }

    private function refundToRollingChipWallet(float $amount): array
    {
        if ($amount <= 0) {
            return [0, 0];
        }

        $rollingChipWallet = $this->walletsDTO->rollingChipWallet;
        if (!is_object($rollingChipWallet)) {
            throw new Exception(
                'Rolling chip wallet not found',
                ErrorCodeEnum::UNDEFINED->value
            );
        }

        $rollingChipService = new RollingChipService($this->member, $rollingChipWallet, $this->vendorInfo->vendor_id);

        $result = $rollingChipService->add($amount, RollingChipLogTypeEnum::BET_CANCEL);

        return [
            $result->beforeBalance,
            $result->afterBalance,
        ];
    }

    private function createTransactionLog(float $amount, float $balanceBefore, float $balanceAfter): void
    {
        if ($amount <= 0) {
            return;
        }

        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $balanceBefore, $balanceAfter);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Rollback, Transaction log failed, Id is empty',
                ErrorCodeEnum::UNDEFINED->value
            );
        }
    }
}
