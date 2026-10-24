<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Libs;

use App\Data\MemberWalletData;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\TfgamingswAccount;
use App\Vendors\Models\TfgamingswBetRecord;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\BetRecordStatusEnum;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use Exception;

class Settle implements LibServiceInterface
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
        if (Common::numCompare($this->dto->amount, 0) < 0) {
            throw new Exception(
                'Settle amount must be greater than 0, this request should be in Unsettle',
                ErrorCodeEnum::INVALID_AMOUNT->value
            );
        }

        $amount = (float) Common::numDiv($this->dto->amount, $this->vendorInfo->rate);

        $this->lockAndSetMember();
        $placeBetRecord = $this->lockAndGetPlaceBetRecord();
        $settleBetRecord = $this->createSettleBetRecord($amount);
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        $walletBalanceBefore = (float) $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = (float) Common::numAdd($walletBalanceBefore, $amount);
        if ($amount > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        // Log
        $placeBetRecord->status = BetRecordStatusEnum::SETTLED;
        $placeBetRecord->save();

        $transactionLogId = $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        // 會有取消後再派彩，需更新
        (new TotalBetRecordService(
            $this->vendorInfo->vendor_id,
            $this->member->id,
            $this->walletsDTO->isMultipleWallet
        ))->createOrUpdate($placeBetRecord, $settleBetRecord, $transactionLogId, 'tfgaming');

        $balance = $walletBalanceAfter;
        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $rollingChipAmount = $this->walletsDTO->rollingChipWallet->balance;
            $balance = Common::numAdd($walletBalanceAfter, $rollingChipAmount);
        }

        return Common::numMul($balance, $this->vendorInfo->rate, 4);
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Member not found', ErrorCodeEnum::INVALID_AUTH->value);
        }
    }

    private function lockAndGetPlaceBetRecord(): TfgamingswBetRecord
    {
        $betRecord = TfgamingswBetRecord::lockForUpdate()
            ->where('bet_id', $this->dto->ticketId)
            ->first();

        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Place bet record not found', ErrorCodeEnum::WITHDRAWAL_NOT_ALLOWED->value);
        }

        return $betRecord;
    }

    private function createSettleBetRecord(float $amount): TfgamingswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = TfgamingswBetRecord::create([
            'bet_id' => $this->dto->ticketNum,
            'reference_bet_id' => $this->dto->ticketId,
            'username' => $this->dto->username,
            'game_code' => 'TP01',
            'status' => BetRecordStatusEnum::IS_SETTLEMENT,
            'infos' => $this->dto->infos,
            'type' => $this->dto->description,

            'bet' => 0,
            'valid_bet' => 0,
            'win_lose' => $amount,

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->settleTime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::UNDEFINED->value);
        }

        return $betRecord;
    }

    /**
     * @throws Exception Transaction log failed.
     */
    private function logTransactionLog(float $amount, float $before, float $after): string
    {
        $amount = Common::numMul($amount, -1);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Settle, Transaction log failed, Id is empty',
                ErrorCodeEnum::UNDEFINED->value
            );
        }

        return (string) $transactionLog->id;
    }
}
