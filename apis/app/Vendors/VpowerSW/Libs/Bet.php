<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Game;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Datas\BetInfoData;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;
use Exception;
use Illuminate\Database\QueryException;

class Bet implements LibServiceInterface
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
        $this->checkVendorInfoAndGameAvailable();

        $this->lockAndSetMember();
        $betRecord = $this->createBetRecord();
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $amount = (float) Common::numDiv($this->dto->balance, $this->vendorInfo->rate);
        $this->checkBalanceEnough($amount);

        $rollingChipPaidAmount = $this->payRollingChip($amount);
        $walletPaidAmount = (float) Common::numSub($amount, $rollingChipPaidAmount);
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

    private function checkVendorInfoAndGameAvailable(): void
    {
        if (!$this->vendorInfo->status) {
            throw new Exception('Vendor Info closed', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        $game = Game::where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('game_type', $this->dto->gameId)
            ->first();
        if (!is_object($game)) {
            throw new Exception('Game not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }
    }

    private function lockAndSetMember(): void
    {
        $this->member = $this->account->member()->lockForUpdate()->first();
        if (!is_object($this->member)) {
            throw new Exception('Account not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->dto->gameId);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }
    }

    /**
     * @throws QueryException, duplicate entry with code 1062.
     * @throws Exception Bet record failed.
     */
    private function createBetRecord(): VpowerswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = VpowerswBetRecord::create([
            'bet_id' => $this->dto->gameRoundId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameId,
            'status' => 0,
            'infos' => $this->dto->infos,
            'type' => $this->dto->type,

            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        return $betRecord;
    }

    private function checkBalanceEnough(float $amount): void
    {
        $oldTotalBalance = $this->walletsDTO->wallet->balance;
        if (is_object($this->walletsDTO->rollingChipWallet)) {
            $oldTotalBalance = Common::numAdd($oldTotalBalance, $this->walletsDTO->rollingChipWallet->balance);
        }

        if (Common::numSub($oldTotalBalance, $amount) < 0) {
            throw new Exception(
                'Wallet balance not enough',
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }
    }

    private function payRollingChip(float $amount): string
    {
        $rollingChipWallet = $this->walletsDTO->rollingChipWallet;
        if (!is_object($rollingChipWallet)) {
            return '0';
        }

        $rollingChipService = new RollingChipService($this->member, $rollingChipWallet, $this->vendorInfo->vendor_id);
        $result = $rollingChipService->sub($amount, RollingChipLogTypeEnum::BET_SETTLE);

        return Common::numSub($result->beforeBalance, $result->afterBalance);
    }

    private function payWallet(float $amount): string
    {
        if ($amount <= 0) {
            return $this->walletsDTO->wallet->balance;
        }

        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numSub($walletBalanceBefore, $amount);
        if ($walletBalanceAfter < 0) { // Double check
            throw new Exception(
                'Wallet balance not enough',
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }

        $this->walletsDTO->wallet->update([
            'balance' => $walletBalanceAfter,
        ]);

        $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        return $walletBalanceAfter;
    }

    /**
     * @throws Exception Transaction log failed.
     */
    private function logTransactionLog(float $amount, string $before, string $after): void
    {
        if ($amount <= 0) {
            return;
        }

        $amount = Common::numMul($amount, -1);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Bet, Transaction log failed, Id is empty',
                ErrorCodeEnum::OTHER_EXCEPTIONS->value
            );
        }
    }
}
