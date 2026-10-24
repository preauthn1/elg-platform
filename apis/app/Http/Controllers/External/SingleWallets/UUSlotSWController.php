<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Requests\External\UUSlotSW;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\UUSlotswAccount;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use App\Vendors\UUSlotSW\VendorInfoDetailData;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-uuslotsw
 */
class UUSlotSWController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::UUSLOTSW->value;

    protected string $accountClass = UUSlotswAccount::class;

    protected string $betRecordClass = UUSlotswBetRecord::class;

    protected string $actionPrefix = 'UUSlot_SW_request_';

    protected string $vendorInfoOperatorField = 'Merchant_Code';

    private VendorInfoDetailData $vendorInfoDetail;

    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $this->logRequest($request, $this->actionPrefix . basename(url()->current()));

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_INCOMING_REQUEST_INFO_INCOMPLETE,
                    sprintf('IP %s not allow', Common::getIp())
                );
            }

            $this->setUpVendorAccount(thirdPartyUsername: (string) $request->input('PlayerId', ''));
            if ($this->account->status == 0) {
                return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_PLAYER, 'Account not found');
            }
            if ((int) $this->vendorInfo->status === 0) {
                return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_OPERATOR_ID, 'Merchant suspend');
            }

            $operatorId = $request->input('OperatorId', '');
            $this->vendorInfoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
            if ($this->vendorInfoDetail->Merchant_Code !== $operatorId) {
                return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_OPERATOR_ID, 'Operator ID Error');
            }

            // 下注資格改由 bet() 在鎖住會員之後以 verifyBettability() 驗；查餘額只看鎖分模式
            if ($method === 'getBalance' && !BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorId)) {
                return $this->errorResponse(StatusCodeEnum::OPERATOR_PLAYER_INACTIVE, 'Account could not bet yet');
            }

            if (!$this->isSignatureValid($request)) {
                return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_SIGNATURE, 'Invalid Signature');
            }

            return parent::callAction($method, $parameters);
        } catch (VendorAccountException $e) {
            return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_PLAYER, 'Account not found');
        } catch (MemberNotFoundException $e) {
            return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_PLAYER, 'Account inactive');
        } catch (VendorNotFoundException $e) {
            return $this->errorResponse(StatusCodeEnum::OPERATOR_INVALID_OPERATOR_ID, 'Merchant Not Found');
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR, $e->getMessage());
        }
    }

    public function getBalance(UUSlotSW\GetBalanceRequest $request): JsonResponse
    {
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'Status' => StatusCodeEnum::OPERATOR_OK,
            'Description' => 'OK',
            'ResponseDateTime' => date('Y-m-d H:i:s'),
            'Balance' => (float) $balance,
        ]);
    }

    public function bet(UUSlotSW\BetRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                // 必須在鎖住會員之後才驗：REPEATABLE READ 的快照定在交易第一次一般讀，
                // 放在鎖之前會讀不到別人剛提交的會員／商戶狀態（target/tickets#4297）
                $this->verifyBettability((string) $request->GameCode);
                $amount = Common::numDiv($request->BetAmount, $this->vendorInfo->rate);

                $oldTotalBalance = $walletDto->getTotalBalance();
                if (Common::numCompare(Common::numSub($oldTotalBalance, $amount), '0') < 0) {
                    throw new Exception('Wallet balance not enough', StatusCodeEnum::OPERATOR_INSUFFICIENT_BALANCE);
                }

                $betRecord = new UUSlotswBetRecord([
                    'bet_id' => (string) $request->BetId,
                    'reference_bet_id' => null,
                    'username' => $this->account->username,
                    'game_code' => $request->GameCode,
                    'status' => BetRecordTypeEnum::BET,
                    'infos' => $request->getContent(),
                    'bet_time' => $request->TranDateTime,
                    'payout_time' => $request->TranDateTime,
                    'bet' => $amount,
                    'valid_bet' => $amount,
                    'win_lose' => Common::numMul($amount, -1),
                    'payout' => 0,
                    'rolling_chips' => 0,
                ]);

                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                return $this->transferResponse(
                    Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                    Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4)
                );
            });
        } catch (BetException $e) {
            return $this->errorResponse(StatusCodeEnum::OPERATOR_PLAYER_INACTIVE, 'Account could not bet yet');
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                    "Place bet bet_id duplicate, bet_id: {$request->BetId}"
                );
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse($e->getCode(), $e->getMessage());
        }
    }

    public function gameResult(UUSlotSW\GameResultRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (UUSlotswBetRecord::where('reference_bet_id', (string) $request->ResultId)->exists()) {
                    throw new Exception(
                        "Result transaction id {$request->ResultId} already processed",
                        StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION
                    );
                }

                $betRecord = UUSlotswBetRecord::lockForUpdate()
                    ->where('bet_id', (string) $request->BetId)
                    ->where('status', BetRecordTypeEnum::BET)
                    ->first();

                if (!$betRecord) {
                    throw new Exception(
                        "Transaction id {$request->BetId} not found",
                        StatusCodeEnum::OPERATOR_BET_TRANSACTION_NOT_FOUND
                    );
                }
                if (!is_null($betRecord->reference_bet_id)) {
                    throw new Exception(
                        "Result of BetId {$request->BetId} already processed",
                        StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION
                    );
                }

                $oldTotalBalance = $walletDto->getTotalBalance();

                $beforeBetRecord = clone $betRecord;
                $betRecord->reference_bet_id = (string) $request->ResultId;
                $betRecord->status = BetRecordTypeEnum::RESULT;
                $betRecord->valid_bet = Common::numDiv($request->ValidBetAmount, $this->vendorInfo->rate);
                $betRecord->payout = Common::numDiv($request->Payout, $this->vendorInfo->rate);
                $betRecord->win_lose = Common::numDiv($request->WinLose, $this->vendorInfo->rate);
                $betRecord->payout_time = $request->TranDateTime;
                $betRecord->result_infos = $request->getContent();

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

                (new TotalBetRecordService(
                    memberId: $this->member->id,
                    vendorId: $this->vendorInfo->vendor_id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId, ['reference_no' => $betRecord->reference_bet_id]);

                return $this->transferResponse(
                    Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                    Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                    "GameResult bet_id or reference_bet_id duplicate, bet_id: {$request->BetId} reference_bet_id: {$request->ResultId}"
                );
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse($e->getCode(), $e->getMessage());
        }
    }

    public function rollback(UUSlotSW\RollbackRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $betRecord = UUSlotswBetRecord::lockForUpdate()
                    ->where('bet_id', (string) $request->BetId)
                    ->first();

                if (!$betRecord) {
                    throw new Exception(
                        "Transaction id {$request->BetId} not found",
                        StatusCodeEnum::OPERATOR_BET_TRANSACTION_NOT_FOUND
                    );
                }
                if ($betRecord->status === BetRecordTypeEnum::ROLLBACK) {
                    throw new Exception(
                        "Transaction id {$request->BetId} already rollback",
                        StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION
                    );
                }

                $oldTotalBalance = $this->vendorInfo->rolling_chip_status
                    ? $walletDto->getTotalBalance()
                    : $walletDto->wallet->balance;

                // Use a temp clone with bet=0 to trigger rolling chip refund in changeBalance.
                // autoSave=false so the original betRecord fields (bet, win_lose) are preserved.
                $beforeBetRecord = clone $betRecord;
                $tempAfterRecord = clone $betRecord;
                $tempAfterRecord->bet = '0';

                $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $tempAfterRecord, autoSave: false);

                $betRecord->status = BetRecordTypeEnum::ROLLBACK;
                $betRecord->payout_time = $request->TranDateTime;
                $betRecord->result_infos = $request->getContent();
                $betRecord->save();

                $newTotalBalance = $this->vendorInfo->rolling_chip_status
                    ? $walletDto->getTotalBalance()
                    : $walletDto->wallet->balance;

                return $this->transferResponse(
                    Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                    Common::numMul($newTotalBalance, $this->vendorInfo->rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                    "Rollback bet_id or reference_bet_id duplicate, bet_id: {$request->BetId} reference_bet_id: {$request->BetId}"
                );
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse($e->getCode(), $e->getMessage());
        }
    }

    public function cashBonus(UUSlotSW\CashBonusRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $payoutAmount = Common::numDiv($request->Payout, $this->vendorInfo->rate);

                $oldTotalBalance = $walletDto->getTotalBalance();

                $betRecord = new UUSlotswBetRecord([
                    'bet_id' => (string) $request->TranId,
                    'reference_bet_id' => (string) $request->TranId,
                    'username' => $this->account->username,
                    'game_code' => 'CashBonus',
                    'status' => BetRecordTypeEnum::BONUS,
                    'infos' => $request->getContent(),
                    'result_infos' => $request->getContent(),
                    'bet_time' => $request->TranDateTime,
                    'payout_time' => $request->TranDateTime,
                    'bet' => 0,
                    'valid_bet' => 0,
                    'payout' => $payoutAmount,
                    'win_lose' => $payoutAmount,
                    'rolling_chips' => 0,
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                $totalBetRecordService = new TotalBetRecordService(
                    memberId: $this->member->id,
                    vendorId: $this->vendorInfo->vendor_id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                );
                $totalBetRecordService->setErrorCode(StatusCodeEnum::CODE_SYSTEM_ERROR);
                $totalBetRecordService->create($betRecord, $transactionLogId, ['bonus' => $payoutAmount]);

                return $this->transferResponse(
                    Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                    Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                    "CashBonus bet_id or reference_bet_id duplicate, bet_id: {$request->TranId} reference_bet_id: {$request->TranId}"
                );
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse($e->getCode(), $e->getMessage());
        }
    }

    public function jackpot(UUSlotSW\JackpotRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $payoutAmount = Common::numDiv($request->Payout, $this->vendorInfo->rate, 4);

                $oldTotalBalance = $walletDto->getTotalBalance();

                $betRecord = new UUSlotswBetRecord([
                    'bet_id' => (string) $request->TranId,
                    'reference_bet_id' => (string) $request->TranId,
                    'username' => $this->account->username,
                    'game_code' => 'Jackpot',
                    'status' => BetRecordTypeEnum::JACKPOT,
                    'infos' => $request->getContent(),
                    'result_infos' => $request->getContent(),
                    'bet_time' => $request->TranDateTime,
                    'payout_time' => $request->TranDateTime,
                    'bet' => 0,
                    'valid_bet' => 0,
                    'payout' => $payoutAmount,
                    'win_lose' => $payoutAmount,
                    'rolling_chips' => 0,
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    memberId: $this->member->id,
                    vendorId: $this->vendorInfo->vendor_id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId, ['jackpot' => $payoutAmount]);

                return $this->transferResponse(
                    Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                    Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(
                    StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                    "Jackpot bet_id or reference_bet_id duplicate, bet_id: {$request->TranId} reference_bet_id: {$request->TranId}"
                );
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse($e->getCode(), $e->getMessage());
        }
    }

    private function isSignatureValid(Request $request): bool
    {
        $signature = $request->input('Signature', '');
        $requestDateTime = $request->input('RequestDateTime', '');
        $operatorId = $request->input('OperatorId', '');
        $playerId = $request->input('PlayerId', '');
        $functionName = trim(basename(url()->current()));

        $uniqueId = match ($functionName) {
            'Bet' => (string) ($request->input('BetId') ?? ''),
            'GameResult' => (string) ($request->input('ResultId') ?? ''),
            'Rollback' => (string) ($request->input('BetId') ?? ''),
            'CashBonus', 'Jackpot' => (string) ($request->input('TranId') ?? ''),
            default => '',
        };

        $secKey = $this->vendorInfoDetail->Security_key ?? '';
        if (empty($secKey)) {
            return false;
        }

        return $signature === md5($functionName . $uniqueId . $requestDateTime . $operatorId . $secKey . $playerId);
    }

    protected function transferResponse(string $oldBalance, string $newBalance): JsonResponse
    {
        return response()->json([
            'Status' => StatusCodeEnum::OPERATOR_OK,
            'Description' => 'OK',
            'ResponseDateTime' => date('Y-m-d H:i:s'),
            'OldBalance' => (float) $oldBalance,
            'NewBalance' => (float) $newBalance,
        ]);
    }

    protected function errorResponse(int $status, string $description): JsonResponse
    {
        return response()->json([
            'Status' => $status,
            'Description' => $description,
            'ResponseDateTime' => date('Y-m-d H:i:s'),
            'Balance' => 0,
            'OldBalance' => 0,
            'NewBalance' => 0,
        ]);
    }
}
