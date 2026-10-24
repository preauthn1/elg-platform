<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\MegaH5SW\OperatorStatusEnum;
use App\Vendors\Models\MegaH5SWAccount;
use App\Vendors\Models\MegaH5SWBetRecord;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MegaH5Controller extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::MEGAH5SW->value;

    protected string $accountClass = MegaH5SWAccount::class;

    protected string $betRecordClass = MegaH5SWBetRecord::class;

    protected string $vendorInfoOperatorField = 'OPERATOR_ID';

    protected string $actionPrefix = 'MegaH5_';

    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR, 'IP not in whitelist');
            }

            $params = $request->all();
            $operatorId = $params['OperatorId'] ?? '';
            $username = $params['PlayerId'] ?? '';

            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_PLAYER);
            }

            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_OPERATOR_ID);
            }

            if ($this->checkSign($request) === false) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE);
            }

            if ($this->checkAuthToken($request) === false) {
                return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR, 'Internal Server Error');
            }

            return parent::callAction($method, $parameters);
        } catch (ValidationException $e) {
            return $this->errorResponse(OperatorStatusEnum::INCOMING_REQUEST_INFO_INCOMPLETE);
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 查詢餘額
     */
    public function getBalance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::PLAYER_INACTIVE);
        }

        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->balanceResponse($balance);
    }

    /**
     * 投注
     */
    public function bet(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::PLAYER_INACTIVE);
        }

        $params = $request->validate([
            'RoundId' => ['required', 'integer'],
            'BetId' => ['required', 'integer'],
            'GameCode' => ['required', 'string', 'max:50'],
            'GameType' => ['required', 'string', 'max:50'],
            'BetAmount' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        try {
            $this->verifyBettability((string) $params['GameCode']);
        } catch (BetException) {
            return $this->errorResponse(OperatorStatusEnum::PLAYER_INACTIVE);
        }

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $roundId = (string) $params['RoundId'];
            $betId = (string) $params['BetId'];
            $username = (string) request()->input('PlayerId');
            $gameCode = $params['GameCode'];
            $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
            $winLose = Common::numSub('0', $bet, 4);
            $time = $params['TranDateTime'];

            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse(OperatorStatusEnum::INSUFFICIENT_BALANCE);
            }

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();
            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'member_id' => $this->member->id,
                'type' => 'N',
                'round_id' => $roundId,
                'bet_id' => $betId,
                'reference_no' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'valid_bet' => $bet,
                'win' => 0,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                // 冪等佔位值，非 null：TotalBetRecordService::create() 以 `$betRecord->payout_time ?? null` 判斷是否帶入該欄位，
                // total_bet_records.payout_time 無預設值，null 會導致 INSERT 漏帶欄位而報錯（比照 WFSW 慣例）。
                // 用 00:00:01 而非 00:00:00：megah5sw_bet_records.payout_time 是 MySQL TIMESTAMP 型別，
                // 合法範圍從 1970-01-01 00:00:01 UTC 起（不含整數秒 0），00:00:00 會報 Incorrect datetime value
                'payout_time' => '1970-01-01 00:00:01',
                'status' => 0,
            ]);
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 派彩/結算（GameResult）
     */
    public function settle(Request $request)
    {
        $params = $request->validate([
            'ResultId' => ['required', 'integer'],
            'BetId' => ['required', 'integer'],
            'RoundId' => ['required', 'integer'],
            'GameCode' => ['required', 'string', 'max:50'],
            'GameType' => ['required', 'string', 'max:50'],
            'GameName' => ['required', 'string', 'max:50'],
            'ResultType' => ['required', 'integer'],
            'BetAmount' => ['required', 'numeric'],
            'ValidBetAmount' => ['required', 'numeric'],
            'Payout' => ['required', 'numeric'],
            'WinLose' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'RoundType' => ['required', 'integer'],
        ]);

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $roundId = (string) $params['RoundId'];
            $betId = (string) $params['BetId'];
            $resultId = (string) $params['ResultId'];
            $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
            $validBet = Common::numDiv($params['ValidBetAmount'], $this->vendorInfo->rate, 4);
            $winLose = Common::numDiv($params['WinLose'], $this->vendorInfo->rate, 4);
            $time = $params['TranDateTime'];

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->lockForUpdate()->first();
            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            // 已結算 → 冪等回傳成功
            if ($oldBetRecord->status == 1) {
                $newBalance = $walletDto->getTotalBalance();

                return $this->response($balance, $newBalance);
            }

            // 已回滾（status=2）→ 不允許重新結算
            if ($oldBetRecord->status == 2) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->round_id = $roundId;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $validBet;
            $betRecord->win = Common::numAdd($winLose, $bet, 4);
            $betRecord->win_lose = $winLose;
            $betRecord->payout_time = $time;
            $betRecord->status = 1;
            $betRecord->reference_no = (string) $resultId;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 回滾投注（Rollback）
     */
    public function rollback(Request $request)
    {
        $params = $request->validate([
            'RoundId' => ['required', 'integer'],
            'BetId' => ['required', 'integer'],
            'GameCode' => ['required', 'string', 'max:50'],
            'GameType' => ['required', 'string', 'max:50'],
            'BetAmount' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'RollbackType' => ['required', 'integer'],
        ]);

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $betId = (string) $params['BetId'];
            $time = $params['TranDateTime'];

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->lockForUpdate()->first();
            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            // 已回滾（status=2）→ 冪等回傳成功
            if ($oldBetRecord->status == 2) {
                $newBalance = $walletDto->getTotalBalance();

                return $this->response($balance, $newBalance);
            }

            // 已結算（status=1）→ 回傳 BET_TRANSACTION_NOT_FOUND 終止重試（INTERNAL_SERVER_ERROR 會讓遊戲商持續重試）
            if ($oldBetRecord->status == 1) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = $time;
            $betRecord->status = 2; // 回滾
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 活動獎金（CashBonus）
     */
    public function bonus(Request $request)
    {
        $params = $request->validate([
            'TranId' => ['required', 'string', 'max:25'],
            'BonusId' => ['required', 'string', 'max:50'],
            'BonusName' => ['required', 'string', 'max:50'],
            'BonusType' => ['required', 'integer'],
            'Payout' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'Result' => ['required', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $tranId = (string) $params['TranId'];
            $bonusId = (string) $params['BonusId'];
            $username = (string) request()->input('PlayerId');
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $time = $params['TranDateTime'];

            $oldBetRecord = $this->betRecordClass::query()->where('reference_no', $tranId)->first();
            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'member_id' => $this->member->id,
                'type' => 'B',
                'bet_id' => $bonusId,
                'reference_no' => $tranId,
                'username' => $username,
                'game_code' => 'CashBonus',
                'bet' => 0,
                'win' => 0,
                'win_lose' => 0,
                'rolling_chip' => 0,
                'bonus' => $win,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => 1,
            ]);
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['bonus' => $win]);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * Jackpot 獎金
     */
    public function jackpot(Request $request)
    {
        $params = $request->validate([
            'TranId' => ['required', 'string', 'max:25'],
            'JackpotId' => ['required', 'string', 'max:50'],
            'Payout' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'Result' => ['required', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $tranId = (string) $params['TranId'];
            $jackpotId = (string) $params['JackpotId'];
            $username = (string) request()->input('PlayerId');
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $time = $params['TranDateTime'];

            $oldBetRecord = $this->betRecordClass::query()->where('reference_no', $tranId)->first();
            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'member_id' => $this->member->id,
                'type' => 'J',
                'bet_id' => $jackpotId,
                'reference_no' => $tranId,
                'username' => $username,
                'game_code' => 'Jackpot',
                'bet' => 0,
                'win' => 0,
                'win_lose' => 0,
                'rolling_chip' => 0,
                'jackpot' => $win,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => 1,
            ]);
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['jackpot' => $win]);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 投注並派彩（BetNSettle，Bet + GameResult 合併一次呼叫）
     */
    public function betNSettle(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::PLAYER_INACTIVE);
        }

        $params = $request->validate([
            'ResultId' => ['required', 'integer'],
            'BetId' => ['required', 'integer'],
            'RoundId' => ['required', 'integer'],
            'GameCode' => ['required', 'string', 'max:50'],
            'GameType' => ['required', 'string', 'max:50'],
            'GameName' => ['required', 'string', 'max:50'],
            'ResultType' => ['required', 'integer'],
            'BetAmount' => ['required', 'numeric'],
            'ValidBetAmount' => ['required', 'numeric'],
            'Payout' => ['required', 'numeric'],
            'WinLose' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'RoundType' => ['required', 'integer'],
        ]);

        try {
            return DB::transaction(function () use ($params) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
                $balance = $walletDto->getTotalBalance();

                $betId = (string) $params['BetId'];
                $resultId = (string) $params['ResultId'];
                $roundId = (string) $params['RoundId'];
                $username = (string) request()->input('PlayerId');
                $gameCode = $params['GameCode'];
                $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
                $validBet = Common::numDiv($params['ValidBetAmount'], $this->vendorInfo->rate, 4);
                $winLose = Common::numDiv($params['WinLose'], $this->vendorInfo->rate, 4);
                $time = $params['TranDateTime'];

                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 併發防線改由 reference_no 唯一索引承擔：撞號者拋 1062，由下方 catch 回 DUPLICATE_TRANSACTION。
                $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();
                if ($oldBetRecord) {
                    return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
                }

                if (Common::numCompare($balance, $bet) < 0) {
                    return $this->errorResponse(OperatorStatusEnum::INSUFFICIENT_BALANCE);
                }

                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'type' => 'N',
                    'round_id' => $roundId,
                    'bet_id' => $betId,
                    'reference_no' => $resultId,
                    'username' => $username,
                    'game_code' => $gameCode,
                    'bet' => $bet,
                    'valid_bet' => $validBet,
                    'win' => Common::numAdd($winLose, $bet, 4),
                    'win_lose' => $winLose,
                    'rolling_chip' => 0,
                    'info' => '[]',
                    'bet_time' => $time,
                    'payout_time' => $time,
                    'status' => 1,
                ]);
                $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                $betRecord->save();

                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                $newBalance = $walletDto->getTotalBalance();

                return $this->response($balance, $newBalance);
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            // 競態條件下的重複注單：交易已整筆回滾，語意同上方既有注單分支
            return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
        }
    }

    /**
     * 補送即時投注結算（CancelBetNSettle）
     *
     * 命名易誤導：此 API 並非「取消 BetNSettle 交易」，而是「取消失敗的投遞，重新補送一次」。
     *
     * 背景：BetNSettle 為遊戲結果確定後的一次性通知。若廠商未收到我方成功回應（逾時或錯誤），
     * 會持續發送 CancelBetNSettle 直到我方回傳 200，確保玩家取得派彩。
     * 因此 BetNSettle 結果本身不可撤銷，此 API 不會扣回玩家已得到的派彩，也不會補建立注單。
     *
     * 處理邏輯：
     * - record 存在（BetNSettle 已處理）→ 冪等回傳 200
     * - record 不存在（BetNSettle 不存在）→ 回傳 900417
     */
    public function cancelBetNSettle(Request $request)
    {
        $params = $request->validate([
            'ResultId' => ['required', 'integer'],
            'BetId' => ['required', 'integer'],
            'RoundId' => ['required', 'integer'],
            'GameCode' => ['required', 'string', 'max:50'],
            'GameType' => ['required', 'string', 'max:50'],
            'GameName' => ['required', 'string', 'max:50'],
            'ResultType' => ['required', 'integer'],
            'BetAmount' => ['required', 'numeric'],
            'ValidBetAmount' => ['required', 'numeric'],
            'Payout' => ['required', 'numeric'],
            'WinLose' => ['required', 'numeric'],
            'ExchangeRate' => ['required', 'numeric'],
            'TranDateTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'RoundType' => ['required', 'integer'],
        ]);

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $betId = (string) $params['BetId'];
            $resultId = (string) $params['ResultId'];
            $roundId = (string) $params['RoundId'];

            // 已處理則直接回傳成功（冪等性）
            $oldBetRecord = $this->betRecordClass::query()
                ->where('type', 'N')
                ->where('status', 1)
                ->where('bet_id', $betId)
                ->where('reference_no', $resultId)
                ->where('round_id', $roundId)
                ->first();

            if ($oldBetRecord) {
                $newBalance = $walletDto->getTotalBalance();

                return $this->response($balance, $newBalance);
            }

            return $this->errorResponse(OperatorStatusEnum::CANCEL_BET_N_SETTLE_REJECTED);
        });
    }

    protected function balanceResponse($balance, OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        // 廠商規格 Balance 欄位為 DECIMAL（範例 1233.5000），floor 到 2 位會捨棄已算出的 4 位精度
        $balance = Common::numFloor(Common::numMul($balance, $this->vendorInfo->rate, 4), 4);

        return response()->json([
            'Status' => $status->value,
            'Description' => $description ?: $status->name,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
            'Balance' => (float) $balance,
        ]);
    }

    protected function response($oldBalance, $newBalance, OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        $oldBalance = Common::numFloor(Common::numMul($oldBalance, $this->vendorInfo->rate, 4), 4);
        $newBalance = Common::numFloor(Common::numMul($newBalance, $this->vendorInfo->rate, 4), 4);

        return response()->json([
            'Status' => $status->value,
            'Description' => $description ?: $status->name,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
            'OldBalance' => (float) $oldBalance,
            'NewBalance' => (float) $newBalance,
        ]);
    }

    protected function errorResponse(OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        return response()->json([
            'Status' => $status->value,
            'Description' => $description ?: $status->name,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * 驗證簽名
     *
     * GetBalance:                          MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)
     * Bet / Rollback:                      MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)
     * GameResult / BetNSettle /
     * CancelBetNSettle:                    MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)
     * CashBonus / Jackpot:                 MD5(FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)
     */
    private function checkSign(Request $request): bool
    {
        $playerId = $request->input('PlayerId', '');
        $operatorId = $request->input('OperatorId', '');
        $signature = $request->input('Signature', '');
        $requestDateTime = $request->input('RequestDateTime', '');
        $functionName = trim(basename(url()->current()));
        $secKey = $this->vendorInfo->info['SECRET_KEY'] ?? '';

        if (empty($secKey)) {
            return false;
        }

        $tranId = match ($functionName) {
            'Bet', 'Rollback' => (string) ($request->input('BetId') ?? ''),
            'GameResult', 'BetNSettle', 'CancelBetNSettle' => (string) ($request->input('ResultId') ?? ''),
            'CashBonus', 'Jackpot' => (string) ($request->input('TranId') ?? ''),
            default => '',
        };

        $expected = md5($functionName . $tranId . $requestDateTime . $operatorId . $secKey . $playerId);

        return hash_equals($expected, $signature);
    }

    private function checkAuthToken(Request $request): bool
    {
        if ($request->has('AuthToken')) {
            return $this->account->auth_token === $request->input('AuthToken');
        }

        return true;
    }
}
