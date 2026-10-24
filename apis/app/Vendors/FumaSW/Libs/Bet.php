<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\FumaSW\Datas\BetInfoData;
use App\Vendors\FumaSW\Enums\BetTypeEnum;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use Exception;
use Illuminate\Database\QueryException;

/**
 * @knowledge rules/vendor-fumasw
 */
class Bet implements LibServiceInterface
{
    private BetInfoData $dto;

    private VendorInfo $vendorInfo;

    private FumaswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings($dto, VendorInfo $vendorInfo, FumaswAccount $account): void
    {
        if (!is_object($dto) || !($dto instanceof BetInfoData)) {
            throw new Exception('Invalid bet info', ErrorCodeEnum::SYSTEM_ERROR);
        }

        $this->dto = $dto;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        if (!$this->vendorInfo->status) {
            throw new Exception('Vendor Info closed', ErrorCodeEnum::SYSTEM_ERROR);
        }

        $this->lockAndSetMember();
        $betRecord = $this->createBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $amount = Common::numDiv($this->dto->bet, $this->vendorInfo->rate, 4);
        if (Common::numCompare($amount, '0', 4) < 0) { // should be positive when bet
            $amount = Common::numMul($amount, -1);
        }
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
        // 暫時註解：此 SELECT FOR UPDATE 在 round_id 索引產生 gap lock，
        // 並發同 round Bet 請求互相擋 insert intention lock → 死結。
        // 防重已改由 FumaSWService::handle() 的 named lock 處理。
        // $betRecord = FumaswBetRecord::lockForUpdate()
        //     ->where('round_id', $this->dto->roundId)
        //     ->where('username', $this->dto->username)
        //     ->where('type', BetTypeEnum::BET)
        //     ->first();
        //
        // if (is_object($betRecord)) {
        //     throw new Exception('Duplicated bet', ErrorCodeEnum::TRANSACTIONID_DUPLICATE);
        // }

        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Account not found', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->dto->gameCode);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::INSUFFICIENT_BALANCE);
        }
    }

    /**
     * @throws QueryException, duplicate entry with code 1062.
     * @throws Exception Bet record failed.
     */
    private function createBetRecord(): FumaswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = FumaswBetRecord::create([
            'bet_id' => $this->dto->transferId,
            'round_id' => $this->dto->roundId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameCode,
            'type' => BetTypeEnum::BET,
            'infos' => json_encode($this->dto->infos),

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::SYSTEM_ERROR);
        }

        return $betRecord;
    }

    private function checkBalanceEnough(string $amount): void
    {
        $oldTotalBalance = $this->walletsDTO->wallet->balance;
        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $oldTotalBalance = Common::numAdd($oldTotalBalance, $this->walletsDTO->rollingChipWallet->balance);
        }

        if (Common::numCompare($oldTotalBalance, $amount) < 0) {
            throw new Exception(
                'Wallet balance not enough',
                ErrorCodeEnum::INSUFFICIENT_BALANCE
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
                ErrorCodeEnum::INSUFFICIENT_BALANCE
            );
        }

        $this->walletsDTO->wallet->update([
            'balance' => $walletBalanceAfter,
        ]);

        $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        return $walletBalanceAfter;
    }

    private function logTransactionLog(string $amount, string $before, string $after): void
    {
        if (Common::numCompare($amount, 0) <= 0) {
            return;
        }

        $amount = Common::numMul($amount, -1);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Bet, Transaction log failed, Id is empty',
                ErrorCodeEnum::SYSTEM_ERROR
            );
        }
    }
}
