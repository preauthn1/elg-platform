<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\JkbswAccount;
use App\Vendors\Models\JkbswBetRecord;
use App\Vendors\Models\JkbswDealRecord;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JokerBaccaratSwController extends BaseController
{
    protected int $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

    protected string $accountClass = JkbswAccount::class;

    protected string $betRecordClass = JkbswBetRecord::class;

    protected string $actionPrefix = 'JokerBaccaratSW_';

    protected string $vendorInfoOperatorField = 'API_ID';

    private const int ACTION_BET = 2;

    private const int ACTION_WIN = 3;

    private const int ACTION_REFUND = 4;

    private const int ACTION_ROLLBACK = 5;

    /**
     * lobby 模式下 callback 不帶 tableCode，BR / TBR 共用此固定 game_code
     * （對齊 migration 2026_05_20_071724_add_jkbsw_lobby_game 建立的 games.game_type）；
     * 需要分桌台時改由 Joker 報表 API 補回。
     */
    private const string GAME_CODE = 'lobby';

    /**
     * 需要解析玩家身份 + 帳號狀態檢查的 endpoint。
     * goodRoad 是純路紙推播，不涉及帳號或錢包，僅做 IP 白名單即可。
     */
    private const array METHODS_REQUIRE_PLAYER = ['balance', 'transfer'];

    /**
     * 統一處理回調前置驗證：IP 白名單 → playerID 解析 → 帳號狀態。
     * 任一不通過直接回 JSON 錯誤，不進入 action method。
     */
    public function callAction($method, $parameters)
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse('IP not allowed');
        }

        if (in_array($method, self::METHODS_REQUIRE_PLAYER, true)) {
            /** @var Request $request */
            $request = $parameters[0];

            $playerID = (string) $request->input('playerID', '');
            if (!$this->resolveByPlayerId($playerID)) {
                return $this->errorResponse('Player not found');
            }

            if ($this->account->status == 0) {
                return $this->errorResponse('Player account is disabled');
            }
        }

        return parent::callAction($method, $parameters);
    }

    // ─── POST /sw/jokerbaccarat/balance/detail ──────────────────────────────

    public function balance(Request $request): JsonResponse
    {
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->successResponse(['balance' => (float) Common::numFloor($balance, 2)]);
    }

    // ─── POST /sw/jokerbaccarat/balance/transfer ────────────────────────────

    public function transfer(Request $request): JsonResponse
    {
        $action = (int) $request->input('action', 0);
        $transactionNo = (string) $request->input('transactionNo', '');
        $roundID = (string) $request->input('roundID', '');
        $amount = Common::numAdd('0', (string) $request->input('amount', 0), 4);

        if ($transactionNo === '' || $roundID === '') {
            return $this->errorResponse('Missing transactionNo or roundID');
        }

        return match ($action) {
            self::ACTION_BET => $this->handleBet($request, $transactionNo, $roundID, $amount),
            self::ACTION_WIN => $this->handleWin($request, $transactionNo, $roundID, $amount),
            self::ACTION_REFUND => $this->handleRefund($request, $transactionNo, $roundID, $amount),
            self::ACTION_ROLLBACK => $this->handleRollback($request, $transactionNo, $roundID, $amount),
            default => $this->errorResponse('Unknown action: ' . $action),
        };
    }

    // ─── POST /sw/jokerbaccarat/goodRoad ────────────────────────────────────

    public function goodRoad(Request $request): JsonResponse
    {
        // 路紙推播僅紀錄；尚未串前端推播。
        // IP 白名單已在 callAction() 統一處理。
        return $this->successResponse();
    }

    // ─── action handlers ────────────────────────────────────────────────────

    private function handleBet(Request $request, string $transactionNo, string $roundID, string $amount): JsonResponse
    {
        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, self::GAME_CODE)) {
            return $this->errorResponse('Member is not able to bet');
        }

        try {
            return DB::transaction(function () use ($request, $transactionNo, $roundID, $amount) {
                // 先 INSERT deal_record 佔位；重送會立刻 1062，業務邏輯不會跑
                $dealRecord = $this->recordDealRecord(
                    transactionNo: $transactionNo,
                    roundID: $roundID,
                    action: self::ACTION_BET,
                    amount: $amount,
                    rawData: $request->all(),
                    betRecordId: null,
                );

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare($walletDto->getTotalBalance(), $amount) < 0) {
                    throw new DomainException('Insufficient balance');
                }

                $balanceBefore = $walletDto->getTotalBalance();

                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
                // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
                $betRecord = JkbswBetRecord::where('bet_id', $roundID)->first();
                $beforeBetRecord = $betRecord ? clone $betRecord : null;

                if (!$betRecord) {
                    $betRecord = new JkbswBetRecord([
                        'vendor_info_id' => $this->vendorInfo->id,
                        'member_id' => $this->member->id,
                        'username' => $this->account->username,
                        'bet_id' => $roundID,
                        'game_code' => self::GAME_CODE,
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win' => '0',
                        'win_lose' => '0',
                        'bet_time' => now(),
                    ]);
                }

                $betRecord->bet = Common::numAdd($betRecord->bet, $amount, 4);
                $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

                // BET 時尚未結算，payout_time 設 sentinel 讓 TBR INSERT 通過（NOT NULL 欄位）
                $betRecord->payout_time ??= '1970-01-01 00:00:00';

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                $dealRecord->update([
                    'bet_record_id' => $betRecord->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $walletDto->getTotalBalance(),
                ]);

                return $this->successResponse([
                    'transactionNo' => $transactionNo,
                    'balance' => (float) $walletDto->getTotalBalance(),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse($transactionNo);
            }
            $this->logException('bet', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage());
        } catch (Throwable $e) {
            $this->logException('bet', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        }
    }

    private function handleWin(Request $request, string $transactionNo, string $roundID, string $amount): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $transactionNo, $roundID, $amount) {
                $dealRecord = $this->recordDealRecord(
                    transactionNo: $transactionNo,
                    roundID: $roundID,
                    action: self::ACTION_WIN,
                    amount: $amount,
                    rawData: $request->all(),
                    betRecordId: null,
                );

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $balanceBefore = $walletDto->getTotalBalance();

                $betRecord = JkbswBetRecord::where('bet_id', $roundID)->lockForUpdate()->first();

                if (!$betRecord) {
                    Log::warning($this->actionPrefix . 'win_no_matching_bet', [
                        'roundID' => $roundID,
                        'transactionNo' => $transactionNo,
                    ]);
                    // rollback deal_record，讓 Joker 重送時可重試
                    throw new DomainException('Round not found');
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();
                $betRecord->status = 1;

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

                // create() 內部用 updateOrCreate on (vendor_id, bet_record_id)：
                // 首次 Win 建 TBR、同 round 後續 Win 自動 mirror BR 最新累加狀態
                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                $dealRecord->update([
                    'bet_record_id' => $betRecord->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $walletDto->getTotalBalance(),
                ]);

                return $this->successResponse([
                    'transactionNo' => $transactionNo,
                    'balance' => (float) $walletDto->getTotalBalance(),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse($transactionNo);
            }
            $this->logException('win', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage());
        } catch (Throwable $e) {
            $this->logException('win', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        }
    }

    private function handleRefund(Request $request, string $transactionNo, string $roundID, string $amount): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $transactionNo, $roundID, $amount) {
                $dealRecord = $this->recordDealRecord(
                    transactionNo: $transactionNo,
                    roundID: $roundID,
                    action: self::ACTION_REFUND,
                    amount: $amount,
                    rawData: $request->all(),
                    betRecordId: null,
                );

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $balanceBefore = $walletDto->getTotalBalance();

                $betRecord = JkbswBetRecord::where('bet_id', $roundID)->lockForUpdate()->first();

                if (!$betRecord) {
                    // 文件未明說，保守做法：保留 deal_record 留審計、回 success（不 rollback）
                    Log::warning($this->actionPrefix . 'refund_no_matching_bet', [
                        'roundID' => $roundID,
                        'transactionNo' => $transactionNo,
                    ]);

                    $dealRecord->update([
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'transactionNo' => $transactionNo,
                        'balance' => (float) $walletDto->getTotalBalance(),
                    ]);
                }

                if ($betRecord->status === -1) {
                    // 已退款；保留 deal_record 作為 Joker 重送的審計
                    $dealRecord->update([
                        'bet_record_id' => $betRecord->id,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'transactionNo' => $transactionNo,
                        'balance' => (float) $walletDto->getTotalBalance(),
                    ]);
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->bet = '0';
                $betRecord->valid_bet = '0';
                $betRecord->win = '0';
                $betRecord->win_lose = '0';
                $betRecord->payout_time = now();
                $betRecord->status = -1;

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);
                unset($transactionLogId);

                $dealRecord->update([
                    'bet_record_id' => $betRecord->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $walletDto->getTotalBalance(),
                ]);

                // Bet→Win→Refund edge case：若 TBR 已被 handleWin 寫入，同步沖銷；
                // 未存在則 update 影響 0 row，不會新建（refund 不應主動產生 TBR）。
                TotalBetRecord::where('vendor_id', $this->vendorInfo->vendor_id)
                    ->where('bet_record_id', $betRecord->id)
                    ->update([
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win_lose' => '0',
                        'bonus' => '0',
                        'jackpot' => '0',
                        'rolling_chip' => '0',
                        'payout_time' => now(),
                    ]);

                return $this->successResponse([
                    'transactionNo' => $transactionNo,
                    'balance' => (float) $walletDto->getTotalBalance(),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse($transactionNo);
            }
            $this->logException('refund', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        } catch (Throwable $e) {
            $this->logException('refund', $e, $transactionNo);

            return $this->errorResponse('Internal server error');
        }
    }

    /**
     * action=5 Rollback：對帳重同步
     * 文檔指引「依 transactionNo 重新比對；若差異以 Joker 為準」，目前先紀錄
     * deal_record + log，實際對齊由對帳 SOP 接手（reconcile-bet-records skill）。
     */
    private function handleRollback(Request $request, string $transactionNo, string $roundID, string $amount): JsonResponse
    {
        Log::warning($this->actionPrefix . 'rollback_received', [
            'transactionNo' => $transactionNo,
            'roundID' => $roundID,
            'amount' => $amount,
            'payload' => $request->all(),
        ]);

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        try {
            $dealRecord = $this->recordDealRecord(
                transactionNo: $transactionNo,
                roundID: $roundID,
                action: self::ACTION_ROLLBACK,
                amount: $amount,
                rawData: $request->all(),
                betRecordId: null,
            );

            $dealRecord->update([
                'balance_before' => $balance,
                'balance_after' => $balance,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                $this->logException('rollback', $e, $transactionNo);
            }
        }

        return $this->successResponse([
            'transactionNo' => $transactionNo,
            'balance' => (float) $balance,
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function resolveByPlayerId(string $playerID): bool
    {
        if ($playerID === '') {
            return false;
        }

        $this->member = $this->getMemberByThirdPartyUsername($playerID);
        if (!$this->member) {
            return false;
        }

        $this->vendorInfo = $this->getVendorInfo('', $this->account);

        return $this->vendorInfo !== null;
    }

    private function recordDealRecord(
        string $transactionNo,
        string $roundID,
        int $action,
        string $amount,
        ?array $rawData,
        ?int $betRecordId,
    ): JkbswDealRecord {
        return JkbswDealRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'bet_record_id' => $betRecordId,
            'bet_id' => $roundID,
            'reference_no' => $transactionNo,
            'action' => $action,
            'amount' => $amount,
            'info' => $rawData,
        ]);
    }

    private function idempotentBalanceResponse(string $transactionNo): JsonResponse
    {
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->successResponse([
            'transactionNo' => $transactionNo,
            'balance' => (float) $balance,
        ]);
    }

    private function logException(string $stage, Throwable $e, string $transactionNo): void
    {
        Log::error($this->actionPrefix . $stage . '_error', [
            'transactionNo' => $transactionNo,
            'error' => $e->getMessage(),
            'trace' => Common::formatTrace($e),
        ]);
    }

    private function successResponse(?array $data = null): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => '',
            'data' => $data,
        ]);
    }

    private function errorResponse(string $message, ?array $data = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
