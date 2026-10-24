<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Libs;

use App\Data\MemberWalletData;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Datas\BetInfoData;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;
use Exception;

class Settlement implements LibServiceInterface
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
        $amount = (float) Common::numDiv($this->dto->balance, $this->vendorInfo->rate);

        $this->lockAndSetMember();
        $placeBetRecord = $this->lockAndGetPlaceBetRecord();
        $settleBetRecord = $this->createSettleBetRecord($amount);
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $walletBalanceBefore = (float) $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = (float) Common::numAdd($walletBalanceBefore, $amount);
        if ($amount > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->update(['balance' => $walletBalanceAfter]);
        }

        // Log
        $placeBetRecord->status = $this->dto->completed;
        $placeBetRecord->save();

        $transactionLogId = $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        (new TotalBetRecordService(
            $this->vendorInfo->vendor_id,
            $this->member->id,
            $this->walletsDTO->isMultipleWallet
        ))->createOrUpdate($placeBetRecord, $settleBetRecord, $transactionLogId, 'vpowersw');

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
            throw new Exception('Account not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }
    }

    private function lockAndGetPlaceBetRecord(): VpowerswBetRecord
    {
        $betRecord = VpowerswBetRecord::lockForUpdate()
            ->where('bet_id', $this->dto->gameRoundId)
            ->first();

        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        // ! Since Vpower old games' completed always 1, so we don't need to check it.
        // else if ( $betRecord->status != 0 ) {
        //     throw new Exception('Bet record already completed', ErrorCodeEnum::OTHER_EXCEPTIONS);
        // }

        return $betRecord;
    }

    private function createSettleBetRecord(float $amount): VpowerswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = VpowerswBetRecord::create([
            'bet_id' => $this->dto->transactionId,
            'reference_bet_id' => $this->dto->gameRoundId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameId,
            'status' => 1,
            'infos' => $this->dto->infos,
            'type' => $this->dto->type,

            'bet' => 0,
            'valid_bet' => 0,
            'win_lose' => $amount,

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
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
                'Bet, Transaction log failed, Id is empty',
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        return (string) $transactionLog->id;
    }
}
