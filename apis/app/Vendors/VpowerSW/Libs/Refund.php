<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Datas\BetInfoData;
use App\Vendors\VpowerSW\Enums\BetTypeEnum;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;
use Exception;

class Refund implements LibServiceInterface
{
    private BetInfoData $dto;

    private VendorInfo $vendorInfo;

    private VpowerswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings(BetInfoData $dto, VendorInfo $vendorInfo, VpowerswAccount $account): void
    {
        $this->dto = $dto;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        if ($this->dto->type !== BetTypeEnum::REFUND->value) {
            throw new Exception(
                "Invalid bet type {$this->dto->type}",
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        $refundAmount = (float) Common::numDiv($this->dto->balance, $this->vendorInfo->rate);

        $this->lockAndSetMember();
        $betRecord = $this->lockAndGetBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        [$walletRefund, $rollingChipRefund] = $this->calcRefunds($betRecord, $refundAmount);
        [$rollingChipBefore, $rollingChipAfter] = $this->refundToRollingChipWallet($rollingChipRefund);
        [$walletBalanceBefore, $walletBalanceAfter] = $this->refundToWallet($walletRefund);

        $newInfos = [
            json_decode($betRecord->infos ?? '[]', true),
            json_decode($this->dto->infos ?? '[]', true),
        ];

        // # Update bet record
        $betRecord->status = 1;
        $betRecord->win_lose = Common::numAdd($refundAmount, $betRecord->win_lose);
        $betRecord->type = $this->dto->type;
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
            throw new Exception('Account not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }
    }

    private function lockAndGetBetRecord(): VpowerswBetRecord
    {
        if (!VpowerswBetRecord::where('bet_id', $this->dto->gameRoundId)->exists()) {
            throw new Exception(
                "Game round id {$this->dto->gameRoundId} not found",
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        $betRecord = VpowerswBetRecord::lockForUpdate()
            ->where('bet_id', $this->dto->gameRoundId)
            ->first();
        if (!is_object($betRecord)) {
            throw new Exception(
                "Game round id {$this->dto->gameRoundId} not found, or already has result",
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        } elseif ($betRecord->status != 0) {
            throw new Exception(
                "Game round id {$this->dto->gameRoundId} already has result",
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        return $betRecord;
    }

    private function calcRefunds(VpowerswBetRecord $betRecord, float $amount): array
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
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        return [$walletRefund, $rollingChipRefund];
    }

    private function refundToWallet(float $amount): array
    {
        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $amount);
        if ($amount > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->update(['balance' => $walletBalanceAfter]);
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
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        $rollingChipService = new RollingChipService($this->member, $rollingChipWallet, $this->vendorInfo->vendor_id);
        $result = $rollingChipService->add($amount, RollingChipLogTypeEnum::BET_CANCEL);

        return [
            $result->beforeBalance,
            $result->afterBalance,
        ];
    }

    private function createTransactionLog(float $amount, string $balanceBefore, string $balanceAfter): void
    {
        if ($amount <= 0) {
            return;
        }

        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $balanceBefore, $balanceAfter);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'RefundBet, Transaction log failed, Id is empty',
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }
    }
}
