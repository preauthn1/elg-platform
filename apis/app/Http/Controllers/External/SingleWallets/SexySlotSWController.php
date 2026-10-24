<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\SexyslotswAccount;
use App\Vendors\Models\SexyslotswBetRecord;
use App\Vendors\Models\SexyslotswDealRecord;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SexySlotSWController extends BaseController implements ShouldVerifyGameBettability
{
    protected string $accountClass = SexyslotswAccount::class;

    protected string $betRecordClass = SexyslotswBetRecord::class;

    protected string $actionPrefix = 'SexySlotSW_';

    public function __construct(protected int $vendorId = VendorIdEnum::SEXYSLOTSW->value) {}

    public function callAction($method, $parameters)
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return response()->json([
                'code' => 402,
                'status' => 'IP_NOT_ALLOWED',
                'message' => sprintf('IP %s not allowed', Common::getIp()),
            ]);
        }

        return parent::callAction($method, $parameters);
    }

    // ─── POST /sw/sexyslotsw/player ──────────────────────────────────────────

    public function player(Request $request): JsonResponse
    {
        if (!$this->verifyHashkey($request)) {
            return $this->playerErrorResponse(402, 'PLAYER_NOT_FOUND', 'Invalid hashkey');
        }

        $details = $request->input('playerdetailsrequest', []);
        $clientPlayerId = (string) ($details['client_player_id'] ?? '');
        $refreshToken = [REDACTED] ($details['refreshtoken'] ?? false);

        $account = SexyslotswAccount::query()
            ->with(['vendorInfo', 'member'])
            ->where('username', $clientPlayerId)
            ->first();

        if (!$account || $account->member_id <= 0 || !$account->member) {
            return $this->playerErrorResponse(402, 'PLAYER_NOT_FOUND', 'Player not found');
        }

        if ($account->status == 0) {
            return $this->playerErrorResponse(402, 'PLAYER_NOT_FOUND', 'Player account is disabled');
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        $newToken = [REDACTED]';
        if ($refreshToken) {
            $newToken = [REDACTED]'-', '', (string) Str::uuid()));
            $account->update(['session_token' => $newToken]);
        }

        return response()->json([
            'playerdetailsresponse' => [
                'status' => [
                    'code' => 200,
                    'status' => 'OK',
                    'message' => 'The request was successfully completed.',
                ],
                'accountid' => $account->username,
                'accountname' => $account->username,
                'username' => $account->username,
                'email' => '',
                'balance' => (float) Common::numFloor(Common::numMul($balance, $this->vendorInfo->rate, 4), 2),
                'currencycode' => $this->vendorInfo->currency,
                'country_code' => 'MY',
                'firstname' => '',
                'lastname' => '',
                'gender' => '',
                'refreshtoken' => $newToken,
            ],
        ]);
    }

    // ─── POST /sw/sexyslotsw/fund ────────────────────────────────────────────

    public function fund(Request $request): JsonResponse
    {
        if (!$this->verifyHashkey($request)) {
            return $this->fundErrorResponse(402, 'OTHER_ERROR', 'Invalid hashkey', 0);
        }

        $transferRequest = $request->input('fundtransferrequest', []);
        $playerInfo = $transferRequest['playerinfo'] ?? [];
        $fundInfo = $transferRequest['fundinfo'] ?? [];
        $gameDetails = $request->input('gamedetails', []);

        $clientPlayerId = (string) ($playerInfo['client_player_id'] ?? '');
        $transactionType = (string) ($fundInfo['transactiontype'] ?? '');
        $transactionId = (string) ($fundInfo['transactionId'] ?? '');
        $roundId = (string) ($fundInfo['roundId'] ?? '');
        $rollback = (bool) ($fundInfo['rollback'] ?? false);
        $amount = Common::numDiv(
            Common::numAdd('0', (string) ($fundInfo['amount'] ?? 0), 4),
            $this->vendorInfo->rate,
            4
        );
        $endround = (bool) ($fundInfo['endround'] ?? false);
        $gameCode = (string) ($gameDetails['gameid'] ?? '');

        if ($transactionId === '' || $roundId === '') {
            return $this->fundErrorResponse(402, 'OTHER_ERROR', 'Missing transactionId or roundId', 0);
        }

        $account = SexyslotswAccount::query()
            ->with(['vendorInfo', 'member'])
            ->where('username', $clientPlayerId)
            ->first();

        if (!$account || $account->member_id <= 0 || !$account->member) {
            return $this->fundErrorResponse(402, 'OTHER_ERROR', 'Player not found', 0);
        }

        if ($account->status == 0) {
            return $this->fundErrorResponse(402, 'OTHER_ERROR', 'Player account is disabled', 0);
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;

        if ($transactionType === 'debit' && !$rollback) {
            try {
                $this->verifyBettability($gameCode);
            } catch (BetException $e) {
                $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

                return $this->fundErrorResponse(402, 'OTHER_ERROR', $e->getMessage(), (float) $balance);
            }
        }

        return match ($transactionType) {
            'debit' => $this->handleDebit($request, $transactionId, $roundId, $amount, $gameCode, $endround),
            'credit' => $rollback
                ? $this->handleRefund($request, $transactionId, $roundId, $amount, $endround)
                : $this->handleCredit($request, $transactionId, $roundId, $amount, $endround),
            default => $this->fundErrorResponse(402, 'OTHER_ERROR', 'Unsupported transactiontype', 0),
        };
    }

    // ─── POST /sw/sexyslotsw/inquiry ─────────────────────────────────────────

    public function inquiry(Request $request): JsonResponse
    {
        if (!$this->verifyHashkey($request)) {
            return response()->json([
                'code' => 404,
                'status' => 'TRANSACTION_NOT_FOUND',
                'data' => ['total_win' => 0],
            ]);
        }

        $roundId = (string) $request->input('roundId', '');
        $transactionId = (string) $request->input('transactionId', '');

        if ($transactionId !== '') {
            $dealRecord = SexyslotswDealRecord::where('reference_no', $transactionId)->first();
            if ($dealRecord) {
                $betRecord = SexyslotswBetRecord::where('bet_id', $dealRecord->bet_id)->first();

                return response()->json([
                    'code' => 200,
                    'status' => 'TRANSACTION_SUCCESS',
                    'data' => ['total_win' => (float) ($betRecord->win ?? 0)],
                ]);
            }

            return response()->json([
                'code' => 404,
                'status' => 'TRANSACTION_NOT_FOUND',
                'data' => ['total_win' => 0],
            ]);
        }

        if ($roundId !== '') {
            $betRecord = SexyslotswBetRecord::where('bet_id', $roundId)->first();
            if ($betRecord) {
                return response()->json([
                    'code' => 200,
                    'status' => 'TRANSACTION_SUCCESS',
                    'data' => ['total_win' => (float) $betRecord->win],
                ]);
            }
        }

        return response()->json([
            'code' => 404,
            'status' => 'TRANSACTION_NOT_FOUND',
            'data' => ['total_win' => 0],
        ]);
    }

    // ─── debit（投注） ───────────────────────────────────────────────────────

    private function handleDebit(
        Request $request,
        string $transactionId,
        string $roundId,
        string $amount,
        string $gameCode,
        bool $endround,
    ): JsonResponse {
        try {
            return DB::transaction(function () use ($request, $transactionId, $roundId, $amount, $gameCode, $endround) {
                $dealRecord = SexyslotswDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $roundId,
                    'reference_no' => $transactionId,
                    'action' => 'debit',
                    'amount' => $amount,
                    'rollback' => false,
                    'endround' => $endround,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare($walletDto->getTotalBalance(), $amount) < 0) {
                    throw new DomainException('INSUFFICIENT_BALANCE');
                }

                $balanceBefore = $walletDto->getTotalBalance();
                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
                // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
                $betRecord = SexyslotswBetRecord::where('bet_id', $roundId)->first();
                $beforeBetRecord = $betRecord ? clone $betRecord : null;

                if (!$betRecord) {
                    $betRecord = new SexyslotswBetRecord([
                        'vendor_info_id' => $this->vendorInfo->id,
                        'member_id' => $this->member->id,
                        'username' => $this->account->username,
                        'bet_id' => $roundId,
                        'game_code' => $gameCode,
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win' => '0',
                        'win_lose' => '0',
                        'bet_time' => now(),
                        'status' => 0,
                    ]);
                }

                $betRecord->bet = Common::numAdd($betRecord->bet, $amount, 4);
                $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

                if ($endround) {
                    $betRecord->payout_time = now();
                    $betRecord->status = 1;
                }

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

                return $this->fundSuccessResponse((float) $walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentFundResponse();
            }
            $this->logException('debit', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        } catch (DomainException $e) {
            $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

            return $this->fundErrorResponse(402, $e->getMessage(), $e->getMessage(), (float) $balance);
        } catch (Throwable $e) {
            $this->logException('debit', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        }
    }

    // ─── credit（派彩） ──────────────────────────────────────────────────────

    private function handleCredit(
        Request $request,
        string $transactionId,
        string $roundId,
        string $amount,
        bool $endround,
    ): JsonResponse {
        try {
            return DB::transaction(function () use ($request, $transactionId, $roundId, $amount, $endround) {
                $dealRecord = SexyslotswDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $roundId,
                    'reference_no' => $transactionId,
                    'action' => 'credit',
                    'amount' => $amount,
                    'rollback' => false,
                    'endround' => $endround,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                $betRecord = SexyslotswBetRecord::where('bet_id', $roundId)->lockForUpdate()->first();

                if (!$betRecord) {
                    Log::warning($this->actionPrefix . 'credit_no_matching_bet', [
                        'roundId' => $roundId,
                        'transactionId' => $transactionId,
                    ]);
                    throw new DomainException('ROUND_NOT_FOUND');
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();

                if ($endround) {
                    $betRecord->status = 1;
                }

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

                return $this->fundSuccessResponse((float) $walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

                return $this->fundErrorResponse(402, 'DUPLICATE_TRANSACTION', 'DUPLICATE_TRANSACTION', (float) $balance);
            }
            $this->logException('credit', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        } catch (DomainException $e) {
            $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

            return $this->fundErrorResponse(402, $e->getMessage(), $e->getMessage(), (float) $balance);
        } catch (Throwable $e) {
            $this->logException('credit', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        }
    }

    // ─── refund（退款，credit + rollback=true） ──────────────────────────────

    private function handleRefund(
        Request $request,
        string $transactionId,
        string $roundId,
        string $amount,
        bool $endround,
    ): JsonResponse {
        try {
            return DB::transaction(function () use ($request, $transactionId, $roundId, $amount, $endround) {
                $dealRecord = SexyslotswDealRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_record_id' => null,
                    'bet_id' => $roundId,
                    'reference_no' => $transactionId,
                    'action' => 'credit',
                    'amount' => $amount,
                    'rollback' => true,
                    'endround' => $endround,
                    'info' => $request->all(),
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $balanceBefore = $walletDto->getTotalBalance();
                $betRecord = SexyslotswBetRecord::where('bet_id', $roundId)->lockForUpdate()->first();

                if (!$betRecord) {
                    throw new DomainException('ROUND_NOT_FOUND');
                }

                if ($betRecord->status === 2) {
                    $dealRecord->update([
                        'bet_record_id' => $betRecord->id,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore,
                    ]);

                    return $this->fundSuccessResponse((float) $walletDto->getTotalBalance());
                }

                $beforeBetRecord = clone $betRecord;

                $betRecord->bet = Common::numSub($betRecord->bet, $amount, 4);
                $betRecord->valid_bet = Common::numSub($betRecord->valid_bet, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
                $betRecord->payout_time = now();
                $betRecord->status = 2;

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

                return $this->fundSuccessResponse((float) $walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->idempotentFundResponse();
            }
            $this->logException('refund', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        } catch (DomainException $e) {
            $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

            return $this->fundErrorResponse(402, $e->getMessage(), $e->getMessage(), (float) $balance);
        } catch (Throwable $e) {
            $this->logException('refund', $e, $transactionId);

            return $this->fundErrorResponse(402, 'INTERNAL_ERROR', 'Internal server error', 0);
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function verifyHashkey(Request $request): bool
    {
        $vendorInfo = $this->findVendorInfo();
        if (!$vendorInfo) {
            return false;
        }

        $this->vendorInfo = $vendorInfo;

        $apiKey = [REDACTED]'CLIENT_API_KEY'] ?? '';
        $accessToken = [REDACTED]'CLIENT_ACCESS_TOKEN'] ?? '';

        if ($apiKey === '' || $accessToken === '') {
            return true;
        }

        $expectedHash = md5($apiKey . $accessToken);
        $receivedHash = (string) $request->input('hashkey', '');

        return hash_equals($expectedHash, $receivedHash);
    }

    private function findVendorInfo(): ?\App\Models\VendorInfo
    {
        return \App\Models\VendorInfo::query()
            ->where('vendor_id', $this->vendorId)
            ->where('status', 1)
            ->first();
    }

    private function fundSuccessResponse(float $balance): JsonResponse
    {
        return response()->json([
            'fundtransferresponse' => [
                'status' => [
                    'code' => 200,
                    'status' => 'SUCCESS',
                    'message' => 'Transaction successful.',
                ],
                'balance' => (float) Common::numFloor(Common::numMul((string) $balance, $this->vendorInfo->rate, 4), 2),
                'currencycode' => $this->vendorInfo->currency,
            ],
        ]);
    }

    private function fundErrorResponse(int $code, string $status, string $message, float $balance): JsonResponse
    {
        return response()->json([
            'fundtransferresponse' => [
                'status' => [
                    'code' => $code,
                    'status' => $status,
                    'message' => $message,
                ],
                'balance' => (float) Common::numFloor(Common::numMul((string) $balance, $this->vendorInfo->rate, 4), 2),
                'currencycode' => $this->vendorInfo->currency ?? 'MYR',
            ],
        ]);
    }

    private function playerErrorResponse(int $code, string $status, string $message): JsonResponse
    {
        return response()->json([
            'playerdetailsresponse' => [
                'status' => [
                    'code' => $code,
                    'status' => $status,
                    'message' => $message,
                ],
            ],
        ]);
    }

    private function idempotentFundResponse(): JsonResponse
    {
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->fundSuccessResponse((float) $balance);
    }

    private function logException(string $stage, Throwable $e, string $transactionId): void
    {
        Log::error($this->actionPrefix . $stage . '_error', [
            'transactionId' => $transactionId,
            'error' => $e->getMessage(),
            'trace' => Common::formatTrace($e),
        ]);
    }
}
