<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Libs;

use App\Data\MemberWalletData;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\Models\WudiswBetRecord;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Enums\BetRecordStatusEnum;
use App\Vendors\WudiSW\Enums\ErrorCodeEnum;
use Exception;

class Reward implements LibServiceInterface
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

        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = WudiswBetRecord::create([
            'bet_id' => $this->dto->orderId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameCode,
            'status' => BetRecordStatusEnum::REWARD,
            'infos' => json_encode([$this->dto->infos]),

            'bet' => $this->dto->bet,
            'valid_bet' => $this->dto->validBet,
            'win_lose' => $amount,

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::SOMETHING_WRONG->value);
        }

        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $amount, 4);
        if (Common::numCompare($amount, '0') > 0) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        // Log

        $transactionLogId = $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        (new TotalBetRecordService(
            $this->vendorInfo->vendor_id,
            $this->member->id,
            $this->walletsDTO->isMultipleWallet
        ))->create($betRecord, $transactionLogId);

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

    /**
     * @throws Exception Transaction log failed.
     */
    private function logTransactionLog(string $amount, string $before, string $after): string
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

        return (string) $transactionLog->id;
    }
}
