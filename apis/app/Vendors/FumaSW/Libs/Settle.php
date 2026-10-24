<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Libs;

use App\Data\MemberWalletData;
use App\Enums\BetRecordStatusEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\FumaSW\Datas\SettleInfoData;
use App\Vendors\FumaSW\Enums\BetTypeEnum;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/single-wallet-unsettled-refund
 * @knowledge rules/vendor-fumasw
 */
class Settle implements LibServiceInterface
{
    private SettleInfoData $dto;

    private VendorInfo $vendorInfo;

    private FumaswAccount $account;

    private Member $member;

    private MemberWalletData $walletsDTO;

    public function setSettings($dto, VendorInfo $vendorInfo, FumaswAccount $account): void
    {
        if (!is_object($dto) || !($dto instanceof SettleInfoData)) {
            throw new Exception('Invalid bet info', ErrorCodeEnum::SYSTEM_ERROR);
        }

        $this->dto = $dto;
        $this->vendorInfo = $vendorInfo;
        $this->account = $account;
    }

    public function exec(): string
    {
        if ($this->dto->win < 0) {
            throw new Exception(
                'Settle amount must be greater than 0, this request should be in Unsettle',
                ErrorCodeEnum::SYSTEM_ERROR
            );
        }

        $amount = Common::numDiv($this->dto->win, $this->vendorInfo->rate);

        $this->lockAndSetMember();
        $placeBetRecord = $this->lockAndGetPlaceBetRecord();

        // 冪等性防護：同 roundId 已有 SETTLE 記錄（不同 transferId 重複回調）
        // → 跳過所有寫入，直接回傳目前餘額，避免雙重入帳
        // if ($this->findExistingSettleRecord() !== null) {
        //     $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
        //     $balance = $this->walletsDTO->wallet->balance;
        //     if (is_object($this->walletsDTO->rollingChipWallet)) {
        //         $balance = Common::numAdd($balance, $this->walletsDTO->rollingChipWallet->balance, 4);
        //     }

        //     return Common::numMul($balance, $this->vendorInfo->rate);
        // }

        $settleBetRecord = $this->createSettleBetRecord($amount);
        $this->walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $walletBalanceBefore = $this->walletsDTO->wallet->balance;
        $walletBalanceAfter = Common::numAdd($walletBalanceBefore, $amount, 4);
        if ($amount > 0 && $this->account->status == 1) {
            $this->walletsDTO->wallet->increment('balance', $amount);
        }

        // Log
        $transactionLogId = $this->logTransactionLog($amount, $walletBalanceBefore, $walletBalanceAfter);

        $totalBetRecordService = new TotalBetRecordService(
            $this->vendorInfo->vendor_id,
            $this->member->id,
            $this->walletsDTO->isMultipleWallet
        );
        if ($placeBetRecord->status === 1) { // 注單已完結
            $totalBetRecordService->create($settleBetRecord, $transactionLogId);
        } else {
            $totalBetRecordService->createOrUpdate($placeBetRecord, $settleBetRecord, $transactionLogId, 'fumasw');
            $placeBetRecord->status = 1;
            $placeBetRecord->payout_time = $this->dto->datetime;
            $placeBetRecord->save();
        }

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
            throw new Exception('Member not found', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }
    }

    /**
     * 查詢同 roundId 是否已存在 SETTLE 記錄（不限 transferId）。
     * 用於防止不同 transferId 對同一回合重複結算。
     */
    private function findExistingSettleRecord(): ?FumaswBetRecord
    {
        return FumaswBetRecord::where('round_id', $this->dto->roundId)
            ->where('username', $this->dto->username)
            ->where('type', BetTypeEnum::SETTLE)
            ->first();
    }

    private function lockAndGetPlaceBetRecord(): FumaswBetRecord
    {
        $betRecord = FumaswBetRecord::lockForUpdate()
            ->where('round_id', $this->dto->roundId)
            ->where('username', $this->dto->username)
            ->where('type', BetTypeEnum::BET)
            ->first();

        if (!is_object($betRecord) || empty($betRecord->id)) {
            // 廠商 2026-09 文件更新，此情境（依 roundId 查無對應下注記錄）改定義為專屬錯誤碼 9012
            throw new Exception('Place bet record not found', ErrorCodeEnum::ROUND_NOT_FOUND);
        }

        // 已由 bet-record-recovery:refund-unsettled 退款的下注列（三方 timeout 判定投注失敗、錢已退回）。
        // 遲到的 action 3 若照常走下去，會把 status 改回 1 並派彩，等同雙重入帳，一律拒絕。
        if ((int) $betRecord->status === BetRecordStatusEnum::REFUND) {
            Log::warning('FumaSW settle rejected: place bet already refunded', [
                'bet_record_id' => $betRecord->id,
                'round_id' => $this->dto->roundId,
                'settle_transfer_id' => $this->dto->transferId,
            ]);

            throw new Exception('Place bet already refunded, settle rejected', ErrorCodeEnum::SYSTEM_ERROR);
        }

        return $betRecord;
    }

    private function createSettleBetRecord(string $amount): FumaswBetRecord
    {
        // Should throw QueryException with code 1062 when entry duplicated
        // bet_id is unique_key.
        $betRecord = FumaswBetRecord::create([
            'bet_id' => $this->dto->transferId,
            'round_id' => $this->dto->roundId,
            'username' => $this->dto->username,
            'game_code' => $this->dto->gameCode,
            'type' => BetTypeEnum::SETTLE,
            'infos' => json_encode($this->dto->infos),
            'is_free_bet' => $this->dto->freewin,
            'bet' => 0,
            'valid_bet' => 0,
            'rolling_chips' => 0,
            'win_lose' => $amount,
            'bet_time' => $this->dto->datetime,
            'payout_time' => $this->dto->datetime,
        ]);
        if (!is_object($betRecord) || empty($betRecord->id)) {
            throw new Exception('Bet record failed', ErrorCodeEnum::SYSTEM_ERROR);
        }

        return $betRecord;
    }

    /**
     * @throws Exception Transaction log failed.
     */
    private function logTransactionLog(string $amount, string $before, string $after): string
    {
        $amount = Common::numMul($amount, -1);
        $service = new TransactionLogCreateService($this->member, $this->vendorInfo, $this->walletsDTO->isMultipleWallet, $this->account);
        $transactionLog = $service->create($amount, $before, $after);

        if (!is_object($transactionLog) || empty($transactionLog->id)) {
            throw new Exception(
                'Settle, Transaction log failed, Id is empty',
                ErrorCodeEnum::SYSTEM_ERROR
            );
        }

        return (string) $transactionLog->id;
    }
}
