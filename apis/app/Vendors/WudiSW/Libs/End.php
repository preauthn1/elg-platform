<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Libs;

use App\Data\MemberWalletData;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Models\WalletTransactionLog;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\Models\WudiswBetRecord;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Enums\BetRecordStatusEnum;
use App\Vendors\WudiSW\Enums\ErrorCodeEnum;
use Exception;

class End implements LibServiceInterface
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
        if ($this->dto->amount < 0) {
            throw new Exception(
                'Settle amount must be greater than 0, this request should be in Unsettle',
                ErrorCodeEnum::BAD_FORMAT_PARAMETERS->value
            );
        }

        $amount = Common::numDiv($this->dto->amount, $this->vendorInfo->rate);

        $this->lockAndSetMember();
        $betRecord = $this->lockAndGetBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $amount, 4);
        if (Common::numCompare($amount, '0') > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        // Log
        $infos = json_decode($betRecord->infos ?? '[]', true);
        $infos[] = $this->dto->infos;

        $betRecord->infos = json_encode($infos);
        $betRecord->status = BetRecordStatusEnum::END;
        $betRecord->payout_time = $this->dto->datetime;
        $betRecord->valid_bet = Common::numDiv($this->dto->validBet, $this->vendorInfo->rate, 4);
        $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
        $betRecord->save();

        $transactionLog = $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        (new TotalBetRecordService(
            $this->vendorInfo->vendor_id,
            $this->member->id,
            $this->walletsDTO->isMultipleWallet
        ))->create($betRecord, $transactionLog->id);

        $balance = $walletBalanceAfter;
        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $rollingChipAmount = $this->walletsDTO->rollingChipWallet->balance;
            $balance = Common::numAdd($walletBalanceAfter, $rollingChipAmount, 4);
        }

        return Common::numMul($balance, $this->vendorInfo->rate);
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
        } elseif ($betRecord->status === BetRecordStatusEnum::END) {
            throw new Exception('Bet record already ended', ErrorCodeEnum::SUCCESS->value);
        } elseif ($betRecord->status !== BetRecordStatusEnum::START) {
            throw new Exception('Bet record already ended', ErrorCodeEnum::TRANSACTION_SETTLED->value);
        }

        return $betRecord;
    }

    private function logTransactionLog(string $amount, string $before, string $after): WalletTransactionLog|TransactionLog
    {
        $amount = Common::numMul($amount, -1, 4);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Settle, Transaction log failed, Id is empty',
                ErrorCodeEnum::SOMETHING_WRONG->value
            );
        }

        return $transactionLog;
    }
}
