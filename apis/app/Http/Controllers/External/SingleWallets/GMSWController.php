<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\GMSW\Driver as GMSWDriver;
use App\Vendors\Models\GMSWAccount;
use App\Vendors\Models\GMSWBetRecord;
use App\Vendors\Models\GMSWDealRecord;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GMSWController extends BaseController
{
    protected string $accountClass = GMSWAccount::class;

    protected string $betRecordClass = GMSWBetRecord::class;

    protected string $actionPrefix = 'GMSW_';

    private const int TYPE_BET = 1;

    private const int TYPE_CANCEL = 2;

    private const int TYPE_WIN = 3;

    private const int TYPE_END_ROUND = 4;

    private const int TYPE_LUCKWIN_BONUS = 5;

    private const int TYPE_FUTURE_FEE = 6;

    // ─── POST /sw/gm/api/luck/user/verify_session ────────────────────────────

    public function verifySession(Request $request): JsonResponse
    {
        if (!$this->verifyIPWhitelist()) {
            return $this->errorResponse(10015, 'IP not allowed');
        }

        $appId = (string) $request->input('app_id', '');
        $sessionToken = [REDACTED] $request->input('operator_player_session', '');

        if (!$this->verifySign($request, $appId)) {
            return $this->errorResponse(10002, 'sign invalid');
        }

        if (!$this->resolveBySessionToken($appId, $sessionToken)) {
            return $this->errorResponse(10011, 'player token verification failed');
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(10014, 'player account is disabled');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);
        $balanceInVendorCurrency = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return $this->successResponse([
            'uname' => $this->account->username,
            'nickname' => $this->account->username,
            'balance' => $balanceInVendorCurrency,
        ]);
    }

    // ─── POST /sw/gm/api/luck/balance/get_balance ────────────────────────────

    public function getBalance(Request $request): JsonResponse
    {
        if (!$this->verifyIPWhitelist()) {
            return $this->errorResponse(10015, 'IP not allowed');
        }

        $appId = (string) $request->input('app_id', '');
        $uname = (string) $request->input('uname', '');

        if (!$this->verifySign($request, $appId)) {
            return $this->errorResponse(10002, 'sign invalid');
        }

        if (!$this->resolveByUsername($appId, $uname)) {
            return $this->errorResponse(10013, 'player not found');
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(10014, 'player account is disabled');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);
        $balanceInVendorCurrency = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return $this->successResponse(['balance' => $balanceInVendorCurrency]);
    }

    // ─── POST /sw/gm/api/luck/balance/change_balance ─────────────────────────

    public function changeBalanceCallback(Request $request): JsonResponse
    {
        if (!$this->verifyIPWhitelist()) {
            return $this->errorResponse(10015, 'IP not allowed');
        }

        $appId = (string) $request->input('app_id', '');
        $uname = (string) $request->input('uname', '');
        $gameId = (int) $request->input('game_id', 0);

        if (!$this->verifySign($request, $appId)) {
            return $this->errorResponse(10002, 'sign invalid');
        }

        if (!$this->resolveByGameId($appId, $gameId)) {
            return $this->errorResponse(10016, 'game not found');
        }

        if (!$this->resolveAccountByUsername($uname)) {
            return $this->errorResponse(10013, 'player not found');
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(10014, 'player account is disabled');
        }

        $type = (int) $request->input('type', 0);
        $sessionId = (string) $request->input('session_id', '');
        $orderId = (string) $request->input('order_id', '');
        $rawMoney = Common::numAdd('0', (string) $request->input('money', 0), 4);
        $rawBet = Common::numAdd('0', (string) $request->input('bet', 0), 4);

        if ($orderId === '' || $sessionId === '') {
            return $this->errorResponse(10009, 'invalid params');
        }

        if ($type === self::TYPE_BET) {
            if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $gameId)) {
                return $this->errorResponse(10014, 'member is not able to bet');
            }
        }

        return match ($type) {
            self::TYPE_BET => $this->handleBet($request, $orderId, $sessionId, $rawMoney, $rawBet, (string) $gameId),
            self::TYPE_CANCEL => $this->handleCancel($request, $orderId, $sessionId, $rawMoney),
            self::TYPE_WIN => $this->handleWin($request, $orderId, $sessionId, $rawMoney),
            self::TYPE_END_ROUND => $this->handleEndRound($request, $orderId, $sessionId),
            self::TYPE_LUCKWIN_BONUS => $this->handleLuckwinBonus($request, $orderId, $sessionId, $rawMoney, (string) $gameId),
            self::TYPE_FUTURE_FEE => $this->handleFutureFee($request, $orderId, $sessionId, $rawMoney),
            default => $this->errorResponse(10009, 'unsupported type: ' . $type),
        };
    }

    // ─── type=1 投注 ─────────────────────────────────────────────────────────

    private function handleBet(
        Request $request,
        string $orderId,
        string $sessionId,
        string $rawMoney,
        string $rawBet,
        string $gameId,
    ): JsonResponse {
        $betAmount = Common::numDiv(
            Common::numCompare($rawMoney, '0') < 0
                ? Common::numMul($rawMoney, '-1', 4)
                : $rawBet,
            $this->vendorInfo->rate,
            4,
        );

        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId, $betAmount, $gameId) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_BET,
                    'amount' => $betAmount,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare($walletDto->getTotalBalance(), $betAmount) < 0) {
                    throw new DomainException('Insufficient balance');
                }

                $balanceBefore = $walletDto->getTotalBalance();
                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
                // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
                $betRecord = GMSWBetRecord::where('bet_id', $sessionId)->first();
                $beforeBetRecord = $betRecord ? clone $betRecord : null;

                if (!$betRecord) {
                    $betRecord = new GMSWBetRecord([
                        'vendor_info_id' => $this->vendorInfo->id,
                        'member_id' => $this->member->id,
                        'username' => $this->account->username,
                        'bet_id' => $sessionId,
                        'game_code' => $gameId,
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win' => '0',
                        'win_lose' => '0',
                        'bet_time' => now(),
                        'status' => 0,
                    ]);
                }

                $betRecord->bet = Common::numAdd($betRecord->bet, $betAmount, 4);
                $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $betAmount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->info = array_merge($betRecord->info ?? [], [$request->all()]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

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
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('bet', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (DomainException $e) {
            return $this->errorResponse(10001, $e->getMessage());
        } catch (Throwable $e) {
            $this->logException('bet', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── type=2 取消投注 ─────────────────────────────────────────────────────

    private function handleCancel(
        Request $request,
        string $orderId,
        string $sessionId,
        string $rawMoney,
    ): JsonResponse {
        $refundAmount = Common::numDiv($rawMoney, $this->vendorInfo->rate, 4);

        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId, $refundAmount) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_CANCEL,
                    'amount' => $refundAmount,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                $betRecord = GMSWBetRecord::where('bet_id', $sessionId)->lockForUpdate()->first();

                if (!$betRecord) {
                    Log::warning($this->actionPrefix . 'cancel_no_matching_bet', [
                        'sessionId' => $sessionId,
                        'orderId' => $orderId,
                    ]);

                    $dealRecord->update([
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                    ]);
                }

                if ($betRecord->status === -1) {
                    $dealRecord->update([
                        'bet_record_id' => $betRecord->id,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                    ]);
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->bet = Common::numSub($betRecord->bet, $refundAmount, 4);
                $betRecord->valid_bet = Common::numSub($betRecord->valid_bet, $refundAmount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();
                $betRecord->status = -1;
                $betRecord->info = array_merge($betRecord->info ?? [], [$request->all()]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

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
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('cancel', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (Throwable $e) {
            $this->logException('cancel', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── type=3 派彩 ─────────────────────────────────────────────────────────

    private function handleWin(
        Request $request,
        string $orderId,
        string $sessionId,
        string $rawMoney,
    ): JsonResponse {
        $winAmount = Common::numDiv($rawMoney, $this->vendorInfo->rate, 4);

        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId, $winAmount) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_WIN,
                    'amount' => $winAmount,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                $betRecord = GMSWBetRecord::where('bet_id', $sessionId)->lockForUpdate()->first();

                if (!$betRecord) {
                    Log::warning($this->actionPrefix . 'win_no_matching_bet', [
                        'sessionId' => $sessionId,
                        'orderId' => $orderId,
                    ]);
                    throw new DomainException('Round not found');
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->win = Common::numAdd($betRecord->win, $winAmount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();
                $betRecord->info = array_merge($betRecord->info ?? [], [$request->all()]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

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
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('win', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (DomainException $e) {
            return $this->errorResponse(10010, $e->getMessage());
        } catch (Throwable $e) {
            $this->logException('win', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── type=4 結算確認 ─────────────────────────────────────────────────────

    private function handleEndRound(
        Request $request,
        string $orderId,
        string $sessionId,
    ): JsonResponse {
        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_END_ROUND,
                    'amount' => '0',
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                $betRecord = GMSWBetRecord::where('bet_id', $sessionId)->lockForUpdate()->first();

                if (!$betRecord) {
                    Log::warning($this->actionPrefix . 'end_round_no_matching_bet', [
                        'sessionId' => $sessionId,
                        'orderId' => $orderId,
                    ]);

                    $dealRecord->update([
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                    ]);
                }

                if ($betRecord->status === 1) {
                    $dealRecord->update([
                        'bet_record_id' => $betRecord->id,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->successResponse([
                        'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                    ]);
                }

                $beforeBetRecord = clone $betRecord;
                $betRecord->payout_time = $betRecord->payout_time ?? now();
                $betRecord->status = 1;
                $betRecord->info = array_merge($betRecord->info ?? [], [$request->all()]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

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
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('end_round', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (Throwable $e) {
            $this->logException('end_round', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── type=5 LuckWin 寶箱加入局 ───────────────────────────────────────────

    private function handleLuckwinBonus(
        Request $request,
        string $orderId,
        string $sessionId,
        string $rawMoney,
        string $gameId,
    ): JsonResponse {
        $bonusAmount = Common::numDiv($rawMoney, $this->vendorInfo->rate, 4);

        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId, $bonusAmount, $gameId) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_LUCKWIN_BONUS,
                    'amount' => $bonusAmount,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
                // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
                $betRecord = GMSWBetRecord::where('bet_id', $sessionId)->first();
                $beforeBetRecord = $betRecord?->exists ? clone $betRecord : null;

                if (!$betRecord) {
                    $betRecord = new GMSWBetRecord([
                        'vendor_info_id' => $this->vendorInfo->id,
                        'member_id' => $this->member->id,
                        'username' => $this->account->username,
                        'bet_id' => $sessionId,
                        'game_code' => $gameId,
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win' => '0',
                        'win_lose' => '0',
                        'bet_time' => now(),
                        'status' => 0,
                    ]);
                }

                $betRecord->bonus = Common::numAdd($betRecord->bonus, $bonusAmount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();
                $betRecord->info = array_merge($betRecord->info ?? [], [$request->all()]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

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
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('luckwin_bonus', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (Throwable $e) {
            $this->logException('luckwin_bonus', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── type=6 Future 遊戲手續費 ────────────────────────────────────────────

    private function handleFutureFee(
        Request $request,
        string $orderId,
        string $sessionId,
        string $rawMoney,
    ): JsonResponse {
        $feeAmount = Common::numDiv(
            Common::numCompare($rawMoney, '0') < 0
                ? Common::numMul($rawMoney, '-1', 4)
                : $rawMoney,
            $this->vendorInfo->rate,
            4,
        );

        try {
            return DB::transaction(function () use ($request, $orderId, $sessionId, $feeAmount) {
                $dealRecord = GMSWDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $sessionId,
                    'reference_no' => $orderId,
                    'action' => self::TYPE_FUTURE_FEE,
                    'amount' => $feeAmount,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare($walletDto->getTotalBalance(), $feeAmount) < 0) {
                    throw new DomainException('Insufficient balance');
                }

                $balanceBefore = $walletDto->getTotalBalance();

                $betRecord = new GMSWBetRecord([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $sessionId . '_fee_' . $orderId,
                    'game_code' => 'future_fee',
                    'bet' => $feeAmount,
                    'valid_bet' => '0',
                    'win' => '0',
                    'win_lose' => Common::numMul($feeAmount, '-1', 4),
                    'bet_time' => now(),
                    'payout_time' => now(),
                    'status' => 1,
                    'info' => [$request->all()],
                ]);

                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                $dealRecord->update([
                    'bet_record_id' => $betRecord->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $walletDto->getTotalBalance(),
                ]);

                return $this->successResponse([
                    'balance' => Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentBalanceResponse();
            }
            $this->logException('future_fee', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        } catch (DomainException $e) {
            return $this->errorResponse(10001, $e->getMessage());
        } catch (Throwable $e) {
            $this->logException('future_fee', $e, $orderId);

            return $this->errorResponse(10015, 'Internal server error');
        }
    }

    // ─── 子廠商解析 ──────────────────────────────────────────────────────────

    /**
     * 以 session_token + app_id 解析子廠商與帳號（verifySession 用）
     */
    private function resolveBySessionToken(string $appId, string $sessionToken): bool
    {
        $vendorIds = array_keys(GMSWDriver::PLATFORM_VENDOR_MAP);

        $account = GMSWAccount::query()
            ->with(['vendorInfo', 'member'])
            ->where('session_token', $sessionToken)
            ->whereHas('vendorInfo', fn ($q) => $q->whereIn('vendor_id', $vendorIds))
            ->first();

        if (!$account || !$account->vendorInfo || !$account->member) {
            return false;
        }

        if (($account->vendorInfo->info['APP_ID'] ?? '') !== $appId) {
            return false;
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorId = $account->vendorInfo->vendor_id;

        return true;
    }

    /**
     * 以 uname + app_id 解析子廠商與帳號（getBalance 用）
     * 同一 app_id 下任一子廠商帳號皆可，餘額從 member wallet 讀取
     */
    private function resolveByUsername(string $appId, string $uname): bool
    {
        $vendorIds = array_keys(GMSWDriver::PLATFORM_VENDOR_MAP);
        $vendorInfos = VendorInfo::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', 1)
            ->get()
            ->filter(fn ($vi) => ($vi->info['APP_ID'] ?? '') === $appId);

        if ($vendorInfos->isEmpty()) {
            return false;
        }

        $account = GMSWAccount::query()
            ->with(['vendorInfo', 'member'])
            ->where('username', $uname)
            ->whereIn('vendor_info_id', $vendorInfos->pluck('id'))
            ->first();

        if (!$account || $account->member_id <= 0 || !$account->member) {
            return false;
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorId = $account->vendorInfo->vendor_id;

        return true;
    }

    /**
     * 以 game_id 查 games 表取得子廠商 vendorInfo（changeBalance 前置）
     */
    private function resolveByGameId(string $appId, int $gameId): bool
    {
        $vendorIds = array_keys(GMSWDriver::PLATFORM_VENDOR_MAP);

        $game = Game::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('game_type', (string) $gameId)
            ->first();

        if (!$game) {
            Log::warning($this->actionPrefix . 'game_not_found', ['game_id' => $gameId]);

            return false;
        }

        $vendorInfo = VendorInfo::query()
            ->where('vendor_id', $game->vendor_id)
            ->where('status', 1)
            ->get()
            ->first(fn ($vi) => ($vi->info['APP_ID'] ?? '') === $appId);

        if (!$vendorInfo) {
            return false;
        }

        $this->vendorId = $game->vendor_id;
        $this->vendorInfo = $vendorInfo;

        return true;
    }

    /**
     * 在 resolveByGameId 之後，以 uname 找帳號（username 全域唯一，不需要 vendor_info_id 過濾）
     */
    private function resolveAccountByUsername(string $uname): bool
    {
        $account = GMSWAccount::query()
            ->with('member')
            ->where('username', $uname)
            ->first();

        if (!$account || $account->member_id <= 0 || !$account->member) {
            return false;
        }

        $this->account = $account;
        $this->member = $account->member;

        return true;
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * 任一 GMSW 子廠商 IP 白名單通過即放行
     */
    private function verifyIPWhitelist(): bool
    {
        foreach (array_keys(GMSWDriver::PLATFORM_VENDOR_MAP) as $vendorId) {
            if (Common::verifyIPWhitelistByVendorId($vendorId)) {
                return true;
            }
        }

        return false;
    }

    private function verifySign(Request $request, string $appId): bool
    {
        if (empty($appId)) {
            return false;
        }

        $vendorIds = array_keys(GMSWDriver::PLATFORM_VENDOR_MAP);
        $vendorInfos = VendorInfo::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', 1)
            ->get();

        $key = null;
        foreach ($vendorInfos as $vi) {
            if (($vi->info['APP_ID'] ?? '') == $appId) {
                $key = $vi->info['KEY'] ?? '';
                break;
            }
        }

        if ($key === null || $key === '') {
            return false;
        }

        $receivedSign = (string) $request->header('sign', '');
        if ($receivedSign === '') {
            return false;
        }

        $traceId = (string) $request->query('trace_id', '');
        $urlParams = $traceId !== '' ? "trace_id={$traceId}" : '';
        $rawBody = $request->getContent();

        return hash_equals(md5($urlParams . $rawBody . $key), $receivedSign);
    }

    private function idempotentBalanceResponse(): JsonResponse
    {
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);
        $balanceInVendorCurrency = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return $this->successResponse(['balance' => $balanceInVendorCurrency]);
    }

    private function logException(string $stage, Throwable $e, string $orderId): void
    {
        Log::error($this->actionPrefix . $stage . '_error', [
            'orderId' => $orderId,
            'error' => $e->getMessage(),
            'trace' => Common::formatTrace($e),
        ]);
    }

    private function successResponse(array $data): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => 'ok',
            'data' => $data,
        ]);
    }

    protected function errorResponse(int $code, string $message): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'msg' => $message,
        ]);
    }
}
