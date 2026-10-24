<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Data\MemberWalletData;
use App\Enums\BetRecordStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\TTHSWAccount;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\Models\TTHSWDealRecord;
use App\Vendors\TTHSW\Enums\ErrorCodeEnum;
use App\Vendors\TTHSW\Enums\TransactionTypeEnum;
use App\Vendors\TTHSW\Exceptions\ResponseDeadlineException;
use App\Vendors\TTHSW\Helpers\ResponseDeadline;
use App\Vendors\TTHSW\Helpers\SignatureHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * @knowledge rules/vendor-tthsw
 */
class TTHSWController extends BaseController
{
    protected string $vendorCode = 'TTHSW';

    protected int $vendorId = VendorIdEnum::TTHSW->value;

    protected string $accountClass = TTHSWAccount::class;

    protected string $betRecordClass = TTHSWBetRecord::class;

    protected string $actionPrefix = 'TTHSW';

    protected array $vendorConfig; // 商戶配置

    /**
     * 回應期限預設值（毫秒），商戶 info 的 RESPONSE_DEADLINE_MS 可覆寫
     */
    private const RESPONSE_DEADLINE_MS = 5000;

    private ?ResponseDeadline $responseDeadline = null;

    /**
     * 預處理 - 統一驗證簽名
     */
    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $action = $this->actionPrefix . '_' . $method;

            $this->logRequest($request, $action);

            // 取得請求資料
            $requestData = $request->all();
            $signature = $request->header('X-Sign');
            $timestamp = $request->header('X-Ts');

            // 驗證必要的 header
            if (!$signature || !$timestamp) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Missing signature or timestamp');
            }

            if ($method === 'auth') {
                if (empty($requestData['AuthToken'])) {
                    return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Missing AuthToken');
                }
                $this->getPlayerByAuthToken($requestData['AuthToken']);
            } else {
                if (empty($requestData['PlayerCode'])) {
                    return $this->errorResponse(ErrorCodeEnum::PLAYER_NOT_FOUND, 'Missing PlayerCode');
                }
                $this->getPlayerByUsername($requestData['PlayerCode']);
            }

            $this->vendorId = $this->vendorInfo->vendor_id;

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'IP not in whitelist');
            }

            // 驗證簽名：用原始 body，$request->all() 已被 TrimStrings 去掉頭尾空白，與三方簽的原文不同
            $rawData = json_decode($request->getContent(), true);
            $signData = is_array($rawData) ? $rawData : $requestData;
            if (!SignatureHelper::verifySignature($signData, $this->vendorConfig['API_KEY'], $signature, (int) $timestamp)) {
                Log::warning($action . '_invalid_signature', [
                    'received_signature' => $signature,
                    'timestamp' => $timestamp,
                    'data' => $signData,
                ]);

                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Invalid signature');
            }

            // 驗證時間戳（防重放攻擊）
            if (!SignatureHelper::verifyTimestamp((int) $timestamp, 300)) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Timestamp expired');
            }

            $this->responseDeadline = ResponseDeadline::fromRequest($request, $this->responseDeadlineMs());

            // 呼叫真正的 controller 方法
            return parent::callAction($method, $parameters);
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_error', [
                'method' => $method ?? 'unknown',
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'System error');
        }
    }

    /**
     * 2.1 Auth
     */
    public function auth(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'AuthToken' => 'required|string',
                'ProviderCode' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 產生 session token
            $sessionToken = [REDACTED];
            $this->account->update(['session_token' => $sessionToken]);

            // 取得玩家餘額
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $balance = $this->convertBalanceByRate($balance);

            return $this->successResponse([
                'PlayerCode' => $this->account->username,
                'Nickname' => $this->member->username,
                'SessionToken' => $sessionToken,
                'Currency' => $this->vendorInfo->currency,
                'Balance' => $balance,
                'City' => $this->vendorConfig['CITY'] ?? '',
                'Country' => $this->vendorConfig['COUNTRY'] ?? '',
                'Ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_auth_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
            ]);

            return $this->errorResponse(ErrorCodeEnum::PLAYER_NOT_FOUND, 'Auth failed');
        }
    }

    /**
     * 2.2 Balance
     */
    public function balance(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 取得玩家餘額
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $balance = $this->convertBalanceByRate($balance);

            return $this->successResponse([
                'SessionToken' => $this->account->session_token ?? '',
                'Balance' => $balance,
            ]);
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_balance_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
            ]);

            return $this->errorResponse(ErrorCodeEnum::PLAYER_NOT_FOUND, 'Balance query failed');
        }
    }

    /**
     * 2.3 Deduct
     */
    public function deduct(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'required|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'Amount' => 'required|numeric|min:0',
                'Type' => 'required|integer',
                'ReserveBetRoundingTolerance' => 'nullable|string',
                'PromotionEventType' => 'nullable|numeric|min:0',
                'PromotionEventRefNo' => 'nullable|string',
                'PromotionEventName' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $data['GameCode']);
            if (!$ableToBet) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Account could not bet yet');
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            // TESTING: 模擬延遲
            if (isset($data['waitSec']) && is_numeric($data['waitSec']) && $data['waitSec'] > 0) {
                sleep((int) $data['waitSec']);
            }

            if ($existingRecord) {
                // 返回之前的處理結果
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            // 根據交易類型處理
            return match ($transactionType) {
                TransactionTypeEnum::BET => $this->processBet($data),
                TransactionTypeEnum::RESERVE => $this->processReserve($data),
                TransactionTypeEnum::RESERVE_BET => $this->processReserveBet($data),
                TransactionTypeEnum::GIFT => $this->processGift($data),
                default => $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Invalid transaction type'),
            };
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'deduct', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_deduct_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Deduct failed');
        }
    }

    /**
     * 處理一般投注
     */
    private function processBet(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 取得錢包並鎖定
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();
            $amount = $this->convertAmountByRate($data['Amount']);

            // 檢查餘額是否足夠
            if (Common::numCompare($currentBalance, $amount) < 0) {
                return $this->insufficientFundResponse($currentBalance);
            }

            $existingBetRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if ($existingBetRecord) {

                // 檢查該回合是否有 Reserve（如果有 Reserve 則不能直接 Bet）
                if ($existingBetRecord->isReserve()) {
                    return $this->errorResponse(
                        ErrorCodeEnum::RESERVE_TRANSACTION_DUPLICATED,
                        'Game round has reserve, should use reserve bet'
                    );
                }

                // 檢查是否已結算
                $settledError = $this->checkBetRecordNotSettled($existingBetRecord, $currentBalance);
                if ($settledError) {
                    return $settledError;
                }

                // 放行時回合結束後同一局仍可能續送扣款（CHOICE Free game），已結算的局維持已結算
                $isSettled = $existingBetRecord->status === BetRecordStatusEnum::SETTLE;

                // 更新現有記錄
                $beforeBetRecord = clone $existingBetRecord;
                $existingBetRecord->bet = Common::numAdd($existingBetRecord->bet, $amount, 4);
                $existingBetRecord->valid_bet = Common::numAdd($existingBetRecord->valid_bet, $amount, 4);
                $existingBetRecord->win_lose = Common::numSub($existingBetRecord->win, $existingBetRecord->bet, 4);
                if (!$isSettled) {
                    $existingBetRecord->status = BetRecordStatusEnum::IN_PROGRESS;
                }

                $transactionLogId = $this->changeBalance(
                    $this->member,
                    $walletDto,
                    $beforeBetRecord,
                    $existingBetRecord,
                    true
                );

                // 已結算的局之後未必還有派彩，TBR 要在這裡跟上新的投注額
                if ($isSettled) {
                    $this->createTotalBetRecord($existingBetRecord, $transactionLogId, $walletDto);
                }

                $betRecord = $existingBetRecord;
            } else {
                // 建立新投注記錄
                $betRecord = $this->createBetRecord($data, $currentBalance);
                $betRecord->status = BetRecordStatusEnum::IN_PROGRESS;

                // 扣除餘額
                $this->changeBalance(
                    $this->member,
                    $walletDto,
                    null,
                    $betRecord,
                    true
                );
            }

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'deduct', $currentBalance, $newBalance);

            return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理 Reserve（預留金額）
     */
    private function processReserve(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 取得錢包並鎖定
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();
            $amount = $this->convertAmountByRate($data['Amount']);

            // 檢查餘額是否足夠
            if (Common::numCompare($currentBalance, $amount) < 0) {
                return $this->insufficientFundResponse($currentBalance);
            }

            // 查詢同局號是否已有 RESERVE 記錄
            $existingReserveRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if ($existingReserveRecord) {
                // 檢查是否已結算
                $settledError = $this->checkBetRecordNotSettled($existingReserveRecord, $currentBalance);
                if ($settledError) {
                    return $settledError;
                }

                $beforeReserveRecord = clone $existingReserveRecord;
                $existingReserveRecord->reserve_amount = Common::numAdd($existingReserveRecord->reserve_amount, $amount, 4);
                $existingReserveRecord->bet = Common::numAdd($existingReserveRecord->bet, $amount, 4);
                $existingReserveRecord->valid_bet = Common::numAdd($existingReserveRecord->valid_bet, $amount, 4);
                $existingReserveRecord->win_lose = Common::numSub($existingReserveRecord->win, $existingReserveRecord->bet, 4);

                $this->changeBalance(
                    $this->member,
                    $walletDto,
                    $beforeReserveRecord,
                    $existingReserveRecord,
                    true
                );

                $betRecord = $existingReserveRecord;
            } else {
                // 建立新 Reserve 記錄
                $betRecord = $this->createBetRecord($data, $currentBalance);
                $betRecord->reserve_amount = $amount;
                $betRecord->reserve_used = '0.0000';
                $betRecord->status = BetRecordStatusEnum::IN_PROGRESS;

                // 扣除餘額
                $this->changeBalance(
                    $this->member,
                    $walletDto,
                    null,
                    $betRecord,
                    true
                );
            }

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'deduct', $currentBalance, $newBalance);

            return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理 Reserve Bet（從預留金額中扣除）
     */
    private function processReserveBet(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // 找到該回合的 Reserve 記錄
            $reserveRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$reserveRecord) {
                return $this->errorResponseWithBalance(
                    ErrorCodeEnum::RESERVE_TRANSACTION_NOT_EXIST,
                    'Reserve transaction not found',
                    $currentBalance
                );
            }

            // 檢查是否已結算
            $settledError = $this->checkBetRecordNotSettled($reserveRecord, $currentBalance);
            if ($settledError) {
                return $settledError;
            }

            // 計算剩餘可用的 Reserve 金額
            $remainingReserve = $reserveRecord->getRemainingReserve();
            $amount = $this->convertAmountByRate($data['Amount']);
            $tolerance = $this->convertAmountByRate($data['ReserveBetRoundingTolerance'] ?? '0.0000');

            // 檢查 Reserve 是否足夠（考慮容差）
            $diff = Common::numSub($remainingReserve, $amount, 4);
            if (Common::numCompare($diff, 0) < 0 && Common::numCompare(abs((float) $diff), (float) $tolerance) > 0) {
                // 不足且超出容差
                return $this->errorResponseWithBalance(
                    ErrorCodeEnum::INSUFFICIENT_RESERVE,
                    'Insufficient reserve amount',
                    $currentBalance
                );
            }

            // 更新記錄
            $reserveRecord->reserve_used = Common::numAdd($reserveRecord->reserve_used, $amount, 4);
            $reserveRecord->save();

            $this->createDealRecord($data, 'deduct', $currentBalance, $currentBalance);

            return $this->successTransactionResponse($reserveRecord, $currentBalance, $currentBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理 Gift（禮物）
     */
    private function processGift(array $data): JsonResponse
    {
        return $this->processBet($data);
    }

    /**
     * 2.4 Win
     */
    public function win(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'required|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'Amount' => 'required|numeric|min:0',
                'Type' => 'required|integer',
                'RefundPreviousWin' => 'nullable|boolean',
                'CreditToWallet' => 'nullable|boolean',
                'CompleteRound' => 'nullable|boolean',
                'PromotionEventType' => 'nullable|numeric|min:0',
                'PromotionEventRefNo' => 'nullable|string',
                'PromotionEventName' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            if ($existingRecord) {
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            // 根據交易類型處理
            return match ($transactionType) {
                TransactionTypeEnum::WIN => $this->processWin($data),
                TransactionTypeEnum::RESETTLE => $this->processWin($data),
                default => $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Invalid transaction type'),
            };
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'win', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_win_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Win failed');
        }
    }

    /**
     * 處理派彩
     */
    private function processWin(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // 找到對應的投注記錄
            $betRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$betRecord) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Transaction not found');
            }

            // 檢查是否已結算
            $settledError = $this->checkBetRecordNotSettled($betRecord, $currentBalance);
            if ($settledError) {
                return $settledError;
            }

            // 檢查是否已退款
            $refundedError = $this->checkBetRecordNotRefunded($betRecord, $currentBalance, __FUNCTION__);
            if ($refundedError) {
                return $refundedError;
            }

            // 更新原投注記錄的派彩
            $beforeBetRecord = clone $betRecord;
            if (!$this->shouldCreditToWallet($data)) {
                if (!empty($data['CompleteRound'])) {
                    $this->applyPayoutToBetRecord($betRecord, $data['Amount'], $this->transactionDateWithoutFraction($data));
                    $betRecord->status = BetRecordStatusEnum::SETTLE;
                    $betRecord->save();
                    $this->createTotalBetRecord($betRecord, 0, $walletDto);
                }

                $newBalance = $walletDto->getTotalBalance();

                $this->createDealRecord($data, 'win', $currentBalance, $newBalance);

                return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
            }

            // 一局可有多筆 Win（每注各一筆），要累加；Resettle 是取代既有派彩
            if ((int) $data['Type'] === TransactionTypeEnum::WIN->value) {
                $this->addPayoutToBetRecord($betRecord, $data['Amount'], $this->transactionDateWithoutFraction($data));
            } elseif ((int) $data['Type'] === TransactionTypeEnum::RESETTLE->value) {
                $this->applyPayoutToBetRecord($betRecord, $data['Amount'], $this->transactionDateWithoutFraction($data));
            }

            // 如果標記為回合完成，更新狀態
            if (!empty($data['CompleteRound'])) {
                $betRecord->status = BetRecordStatusEnum::SETTLE;
            }

            // 更新餘額
            $transactionLogId = $this->changeBalance(
                $this->member,
                $walletDto,
                $beforeBetRecord,
                $betRecord,
                true
            );

            // 建立總注單記錄
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'win', $currentBalance, $newBalance);

            return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 2.5 Refund
     */
    public function refund(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'required|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'Amount' => 'required|numeric',
                'Type' => 'required|integer',
                'GameCode' => 'nullable|string',
                'OriginalTransactionId' => 'nullable|string',
                'ReserveBetRoundingTolerance' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            if ($existingRecord) {
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            // 根據交易類型處理
            return match ($transactionType) {
                TransactionTypeEnum::CANCEL_ROUND => $this->processCancelRound($data),
                TransactionTypeEnum::CANCEL_TRANSACTION => $this->processCancelTransaction($data),
                TransactionTypeEnum::ADJUST => $this->processAdjust($data),
                TransactionTypeEnum::RELEASE_RESERVE => $this->processReleaseReserve($data),
                default => $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Invalid transaction type'),
            };
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'refund', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_refund_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Refund failed');
        }
    }

    /**
     * 處理取消整個遊戲回合
     */
    private function processCancelRound(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // 找到對應的投注記錄
            $betRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$betRecord) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Transaction not found');
            }

            // 檢查是否已結算
            $settledError = $this->checkBetRecordNotSettled($betRecord, $currentBalance);
            if ($settledError) {
                return $settledError;
            }

            // 檢查是否已退款：換一個 TransactionId 重送的整局取消要在這裡擋下
            $refundedError = $this->checkBetRecordNotRefunded($betRecord, $currentBalance, __FUNCTION__);
            if ($refundedError) {
                return $refundedError;
            }

            // 退還所有金額
            $beforeBetRecord = clone $betRecord;
            $betRecord->bet = '0.0000';
            $betRecord->valid_bet = '0.0000';
            $betRecord->win = '0.0000';
            $betRecord->win_lose = '0.0000';
            $betRecord->payout_time = $this->transactionDateWithoutFraction($data);
            $betRecord->status = BetRecordStatusEnum::REFUND;

            // 更新餘額
            $transactionLogId = $this->changeBalance(
                $this->member,
                $walletDto,
                $beforeBetRecord,
                $betRecord,
                true
            );

            // 建立總注單記錄
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'refund', $currentBalance, $newBalance);

            return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理取消單筆交易
     */
    private function processCancelTransaction(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 找到原始交易ID
            if (empty($data['OriginalTransactionId'])) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Original transaction ID required');
            }

            // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // 找到該回合的投注記錄
            $roundRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$roundRecord) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Game round not found');
            }

            // 檢查是否已結算
            $settledError = $this->checkBetRecordNotSettled($roundRecord, $currentBalance);
            if ($settledError) {
                return $settledError;
            }

            // 檢查整局是否已退款：CANCEL_ROUND 之後注單的 bet 已歸零，再撤單會把它扣成負數並入帳
            $refundedError = $this->checkBetRecordNotRefunded($roundRecord, $currentBalance, __FUNCTION__);
            if ($refundedError) {
                return $refundedError;
            }

            // 從 Deal 記錄中查找原始交易
            $dealRecord = TTHSWDealRecord::where('game_round_id', $data['GameRoundId'])
                ->where('transaction_id', $data['OriginalTransactionId'])
                ->whereNot('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
                ->first();

            if (!$dealRecord) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Original transaction not found');
            }

            // 同一筆原交易只能撤一次：上面的去重只認撤單自己的 TransactionId，三方換號重送時擋不到
            $existingCancel = $this->findCancelForOriginalTransaction($data['GameRoundId'], $data['OriginalTransactionId']);
            if ($existingCancel) {
                Log::warning($this->actionPrefix . '_cancel_transaction_already_canceled', [
                    'game_round_id' => $data['GameRoundId'],
                    'original_transaction_id' => $data['OriginalTransactionId'],
                    'transaction_id' => $data['TransactionId'],
                    'existing_cancel_transaction_id' => $existingCancel->transaction_id,
                ]);

                return $this->originalTransactionCanceledResponse($data, $existingCancel, $roundRecord, $currentBalance);
            }

            $cancelAmount = $dealRecord->amount ?? null;
            if ($cancelAmount === null) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Transaction amount not found');
            }

            // 從累計記錄中扣除該筆金額
            $beforeRecord = clone $roundRecord;

            $roundRecord->bet = Common::numSub($roundRecord->bet, $cancelAmount, 4);
            $roundRecord->valid_bet = Common::numSub($roundRecord->valid_bet, $cancelAmount, 4);
            $roundRecord->win_lose = Common::numAdd($roundRecord->win_lose, $cancelAmount, 4);
            $roundRecord->payout_time = $this->transactionDateWithoutFraction($data);

            $transactionLogId = $this->changeBalance(
                $this->member,
                $walletDto,
                $beforeRecord,
                $roundRecord,
                true
            );

            $this->createTotalBetRecord($roundRecord, $transactionLogId, $walletDto);

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'refund', $currentBalance, $newBalance);

            return $this->successTransactionResponse($roundRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理調整
     */
    private function processAdjust(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 取得錢包並鎖定
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // Amount 可以是正數（加錢）或負數（扣錢）
            $amount = $this->convertAmountByRate($data['Amount']);

            // 游戏商反馈扣到0返回成功
            /*
            if (Common::numCompare($amount, 0) < 0 && Common::numCompare($currentBalance, abs((float) $amount)) < 0) {
                return $this->insufficientFundResponse($currentBalance);
            }
            */

            // 找到對應的投注記錄
            $betRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$betRecord) {
                return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_NOT_EXIST, 'Game round not found');
            }

            // 檢查是否已結算
            $settledError = $this->checkBetRecordNotSettled($betRecord, $currentBalance);
            if ($settledError) {
                return $settledError;
            }

            // 檢查是否已退款：已整局退光的注單不應再被調整派彩
            $refundedError = $this->checkBetRecordNotRefunded($betRecord, $currentBalance, __FUNCTION__);
            if ($refundedError) {
                return $refundedError;
            }

            $beforeBetRecord = clone $betRecord;
            // 調整派彩金額
            $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
            $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
            $betRecord->payout_time = $this->transactionDateWithoutFraction($data);

            $transactionLogId = $this->changeBalance(
                $this->member,
                $walletDto,
                $beforeBetRecord,
                $betRecord,
                true
            );

            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'refund', $currentBalance, $newBalance);

            return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 處理釋放預留金額
     */
    private function processReleaseReserve(array $data): JsonResponse
    {
        return DB::transaction(function () use ($data) {
            // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = $this->lockWallets();
            $currentBalance = $walletDto->getTotalBalance();

            // 找到 Reserve 記錄
            $reserveRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

            if (!$reserveRecord) {
                return $this->errorResponse(ErrorCodeEnum::RESERVE_TRANSACTION_NOT_EXIST, 'Reserve transaction not found');
            }

            // 計算剩餘的 Reserve 金額
            $remainingReserve = $reserveRecord->getRemainingReserve();
            $tolerance = $data['ReserveBetRoundingTolerance'] ?? '0.0000';

            // 檢查是否已退款：整局取消已把預留額退光，這裡再退一次等於重複退款
            $refundedError = $this->checkBetRecordNotRefunded($reserveRecord, $currentBalance, __FUNCTION__);
            if ($refundedError) {
                return $refundedError;
            }

            // 檢查是否在容差範圍內
            if (Common::numCompare($remainingReserve, 0) < 0 && Common::numCompare(abs((float) $remainingReserve), (float) $tolerance) > 0) {
                // 超出容差，報錯
                return $this->errorResponseWithBalance(
                    ErrorCodeEnum::INSUFFICIENT_RESERVE,
                    'Insufficient reserve amount',
                    $currentBalance
                );
            }

            // 如果剩餘金額大於 0，退還給玩家
            $refundAmount = max(0, (float) $remainingReserve);

            if ($refundAmount > 0) {
                // 退還餘額
                $beforeBetRecord = clone $reserveRecord;
                $reserveRecord->bet = $reserveRecord->getReserveUsed();
                $reserveRecord->valid_bet = $reserveRecord->getReserveUsed();
                $reserveRecord->win_lose = Common::numSub($reserveRecord->win, $reserveRecord->bet, 4);

                $this->changeBalance(
                    $this->member,
                    $walletDto,
                    $beforeBetRecord,
                    $reserveRecord,
                    true
                );
            }

            $newBalance = $walletDto->getTotalBalance();

            $this->createDealRecord($data, 'refund', $currentBalance, $newBalance);

            return $this->successTransactionResponse($reserveRecord, $currentBalance, $newBalance, $data['TransactionId']);
        }, 3);
    }

    /**
     * 2.6 Complete
     */
    public function complete(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'required|string',
                'GameCode' => 'nullable|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'TotalPayout' => 'required|numeric',
                'CreditToWallet' => 'nullable|boolean',
                'Type' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            if ($existingRecord) {
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            return DB::transaction(function () use ($data) {
                // 鎖序統一為「會員 → 注單」，與 processBet／processReserve 一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = $this->lockWallets();
                $currentBalance = $walletDto->getTotalBalance();

                // 用 GameRoundId 查詢投注記錄
                $betRecord = $this->findBetRecordByGameRound($data['GameRoundId']);

                if (!$betRecord) {
                    return $this->errorResponse(ErrorCodeEnum::GAME_ROUND_TRANSACTION_NOT_EXIST, 'Transaction not found');
                }

                if (empty($data['GameCode'])) {
                    $data['GameCode'] = $betRecord->game_code;
                }

                // 檢查是否已結算
                $settledError = $this->checkBetRecordNotSettled($betRecord, $currentBalance);
                if ($settledError) {
                    return $settledError;
                }

                // 檢查是否已退款：整局取消後注單的 bet 已歸零，這裡若照常結算會在已退光的局上再派一次彩
                $refundedError = $this->checkBetRecordNotRefunded($betRecord, $currentBalance, __FUNCTION__);
                if ($refundedError) {
                    return $refundedError;
                }

                // 標記投注記錄為已結算
                $beforeBetRecord = clone $betRecord;
                $betRecord->status = BetRecordStatusEnum::SETTLE;
                $transactionLogId = 0;

                if ($this->shouldCreditToWallet($data)) {
                    $this->applyPayoutToBetRecord($betRecord, $data['TotalPayout'], $this->transactionDateWithoutFraction($data));
                    $transactionLogId = $this->changeBalance(
                        $this->member,
                        $walletDto,
                        $beforeBetRecord,
                        $betRecord,
                        true
                    );
                } else {
                    // 不入帳的總額只代表回合結束，注單派彩維持先前已入帳的派彩累計
                    $betRecord->payout_time = $this->transactionDateWithoutFraction($data);
                    $betRecord->save();
                }

                $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

                $newBalance = $walletDto->getTotalBalance();

                $data['Amount'] = $data['TotalPayout'];
                $this->createDealRecord($data, 'complete', $currentBalance, $newBalance);

                return $this->successTransactionResponse($betRecord, $currentBalance, $newBalance, $data['TransactionId']);
            }, 3);
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'complete', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_complete_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Complete failed');
        }
    }

    /**
     * 2.7 Refresh Token
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 產生新的 session token
            $sessionToken = [REDACTED];
            $this->account->update(['session_token' => $sessionToken]);

            // 取得玩家餘額
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $balance = $this->convertBalanceByRate($balance);

            return $this->successResponse([
                'SessionToken' => $sessionToken,
                'Balance' => $balance,
            ]);
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_refreshToken_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
            ]);

            return $this->errorResponse(ErrorCodeEnum::PLAYER_NOT_FOUND, 'Refresh token failed');
        }
    }

    /**
     * 2.8 Promo Win
     */
    public function promoWin(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'required|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'Amount' => 'required|numeric',
                'Type' => 'required|integer',
                'PromotionEventType' => 'nullable|numeric|min:0',
                'PromotionEventRefNo' => 'nullable|string',
                'PromotionEventName' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            if ($existingRecord) {
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            return DB::transaction(function () use ($data) {
                // 取得錢包並鎖定
                $walletDto = $this->lockWallets();
                $currentBalance = $walletDto->getTotalBalance();

                // 如果余额不足，则需要将玩家剩余余额清零并返回成功。经游戏方确认，这种情况下后台显示的交易金额仍会显示一样
                /*
                $amount = $data['Amount'];
                if (Common::numCompare($amount, 0) < 0 && Common::numCompare($currentBalance, abs((float) $amount)) < 0) {
                    return $this->insufficientFundResponse($currentBalance);
                }
                */

                // 建立 PromoWin 記錄（無投注的純獎金）
                $promoRecord = $this->createPromoWinRecord($data);
                $promoRecord->status = BetRecordStatusEnum::SETTLE;

                $transactionLogId = $this->changeBalance(
                    $this->member,
                    $walletDto,
                    null,
                    $promoRecord,
                    true
                );

                // 建立總注單記錄
                $this->createTotalBetRecord($promoRecord, $transactionLogId, $walletDto);

                $newBalance = $walletDto->getTotalBalance();

                $this->createDealRecord($data, 'promowin', $currentBalance, $newBalance);

                return $this->successTransactionResponse($promoRecord, $currentBalance, $newBalance, $data['TransactionId']);
            }, 3);
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'promoWin', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_promoWin_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'PromoWin failed');
        }
    }

    /**
     * 2.9 Wallet
     */
    public function wallet(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'PlayerCode' => 'required|string',
                'ProviderCode' => 'required|string',
                'GameRoundId' => 'nullable|string',
                'TransactionId' => 'required|string',
                'TransactionDate' => 'required|string',
                'Currency' => 'required|string',
                'Amount' => 'required|numeric|min:0',
                'Type' => 'required|integer|in:15,16',
                'Reason' => 'nullable|string',
                'CompleteRound' => 'required|boolean',
                'GameCode' => 'nullable|string',
                'PromotionEventType' => 'nullable|numeric|min:0',
                'PromotionEventRefNo' => 'nullable|string',
                'PromotionEventName' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, $validator->errors()->first());
            }

            // 驗證幣別
            $this->verifyCurrency($data['Currency']);

            // 有些供应商不包含 GameRoundId
            if (!isset($data['GameRoundId'])) {
                $data['GameRoundId'] = $data['TransactionId'];
            }

            $transactionType = TransactionTypeEnum::from($data['Type']);

            // 檢查交易是否已處理（去重）
            $existingRecord = $this->findExistingTransaction(
                $data['GameRoundId'],
                $data['TransactionId'],
                $transactionType
            );

            if ($existingRecord) {
                return $this->transactionDuplicatedResponse($data['GameRoundId'], $data['TransactionId'], $transactionType);
            }

            return DB::transaction(function () use ($data, $transactionType) {
                // 取得錢包並鎖定
                $walletDto = $this->lockWallets();
                $currentBalance = $walletDto->getTotalBalance();

                // 建立 Wallet 記錄
                $walletRecord = $this->createWalletRecord($data, $transactionType);
                $walletRecord->status = BetRecordStatusEnum::SETTLE;

                $transactionLogId = $this->changeBalance(
                    $this->member,
                    $walletDto,
                    null,
                    $walletRecord,
                    true
                );

                // 建立總注單記錄
                $this->createTotalBetRecord($walletRecord, $transactionLogId, $walletDto);

                $newBalance = $walletDto->getTotalBalance();

                $this->createDealRecord($data, 'wallet', $currentBalance, $newBalance);

                return $this->successTransactionResponse($walletRecord, $currentBalance, $newBalance, $data['TransactionId']);
            }, 3);
        } catch (ResponseDeadlineException $e) {
            return $this->responseDeadlineExceededResponse($e, 'wallet', $request->all());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_wallet_error', [
                'message' => $e->getMessage(),
                'data' => $request->all(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(ErrorCodeEnum::INVALID_SIGN, 'Wallet transaction failed');
        }
    }

    // ==================== 輔助方法 ====================

    private function transactionDateWithoutFraction(array $data): string
    {
        return $this->formatTransactionDateWithoutFraction((string) $data['TransactionDate']);
    }

    private function formatTransactionDateWithoutFraction(string $transactionDate): string
    {
        return preg_replace('/(?<=\d{2}:\d{2}:\d{2})[\.,]\d+/', '', $transactionDate) ?? $transactionDate;
    }

    /**
     * 透過 authToken 取得玩家
     */
    private function getPlayerByAuthToken(string $authToken): void
    {
        /** @var TTHSWAccount $account */
        $account = TTHSWAccount::with(['vendorInfo', 'member'])
            ->where('auth_token', $authToken)
            ->first();

        if (!$account || !$this->validateAccount($account)) {
            Log::warning($this->vendorCode . ' player lookup failed by auth_token', ['auth_token' => $authToken]);
            throw new ApiException('Player not found');
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorConfig = $account->vendorInfo->info;
    }

    /**
     * 透過 username 取得玩家
     */
    private function getPlayerByUsername(string $username): void
    {
        /** @var TTHSWAccount $account */
        $account = TTHSWAccount::with(['vendorInfo', 'member'])
            ->where('username', $username)
            ->first();

        if (!$account || !$this->validateAccount($account)) {
            Log::warning($this->vendorCode . ' player lookup failed by username', ['username' => $username]);
            throw new ApiException('Player not found');
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorConfig = $account->vendorInfo->info;
    }

    /**
     * 驗證帳號狀態
     */
    private function validateAccount(TTHSWAccount $account): bool
    {
        if ($account->status != 1) {
            return false;
        }
        if (empty($account->vendorInfo) || $account->vendorInfo->status != 1) {
            return false;
        }
        if (empty($account->member) || $account->member->status != 1) {
            return false;
        }

        return true;
    }

    /**
     * 驗證幣別
     *
     * @throws ApiException
     */
    private function verifyCurrency(string $currency): void
    {
        if ($currency !== $this->vendorInfo->currency) {
            Log::warning($this->actionPrefix . '_currency_mismatch', [
                'received_currency' => $currency,
                'expected_currency' => $this->vendorInfo->currency,
                'vendor_id' => $this->vendorId,
            ]);
            throw new ApiException('Currency mismatch');
        }
    }

    /**
     * 產生 Session Token
     */
    private function generateSessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 查詢已存在的交易
     */
    private function findExistingTransaction(string $gameRoundId, string $transactionId, TransactionTypeEnum $type): ?TTHSWDealRecord
    {
        return TTHSWDealRecord::where('game_round_id', $gameRoundId)
            ->where('transaction_id', $transactionId)
            ->where('type', $type->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 用 GameRoundId 查詢投注記錄
     */
    private function shouldCreditToWallet(array $data): bool
    {
        return $this->booleanValue($data, 'CreditToWallet', false);
    }

    private function booleanValue(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    private function addPayoutToBetRecord(TTHSWBetRecord $betRecord, string $amount, string $payoutTime): void
    {
        $amount = $this->convertAmountByRate($amount);
        $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
        $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
        $betRecord->payout_time = $payoutTime;
    }

    private function applyPayoutToBetRecord(TTHSWBetRecord $betRecord, string $amount, string $payoutTime): void
    {
        $amount = $this->convertAmountByRate($amount);
        $betRecord->win = $amount;
        $betRecord->win_lose = Common::numSub($amount, $betRecord->bet, 4);
        $betRecord->payout_time = $payoutTime;
    }

    private function findBetRecordByGameRound(string $gameRoundId): ?TTHSWBetRecord
    {
        return TTHSWBetRecord::where('bet_id', $gameRoundId)
            ->where('username', $this->account->username)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 檢查注單是否已結算，如果已結算則返回錯誤
     *
     * 商戶 info 設 ALLOW_AFTER_COMPLETE_ROUND = true 時放行：該遊戲商回合結束後同一局仍會續送交易
     * （CHOICE 買 Free game 先結束回合再送每轉扣款與派彩）。
     */
    private function checkBetRecordNotSettled(TTHSWBetRecord $betRecord, string $currentBalance): ?JsonResponse
    {
        if ($betRecord->status === BetRecordStatusEnum::SETTLE && !$this->allowsAfterCompleteRound()) {
            return $this->errorResponseWithBalance(
                ErrorCodeEnum::ERROR,
                'Game round already settled',
                $currentBalance
            );
        }

        return null;
    }

    private function allowsAfterCompleteRound(): bool
    {
        return $this->booleanValue($this->vendorConfig, 'ALLOW_AFTER_COMPLETE_ROUND', false);
    }

    /**
     * 整局已退款（CANCEL_ROUND 把 bet 歸零並改 REFUND）之後，任何還會動錢包的請求都要擋下。
     *
     * processWin、complete() 與四條退款分支（CANCEL_ROUND／CANCEL_TRANSACTION／ADJUST／RELEASE_RESERVE）
     * 都會先過這道檢查。少了它，「先 CANCEL_ROUND 再 CANCEL_TRANSACTION」會在已退光的注單上再退一次：
     * bet 已是 0，仍會被扣成負數並入帳（target/op_tickets#3099）。
     */
    private function checkBetRecordNotRefunded(TTHSWBetRecord $betRecord, string $currentBalance, string $context): ?JsonResponse
    {
        if ($betRecord->status !== BetRecordStatusEnum::REFUND) {
            return null;
        }

        // 這道守衛擋掉的是三方仍在送的請求，誤擋（例如 TTH 對已退款局有合法的 ADJUST 校正）在線上必須看得見
        Log::warning($this->actionPrefix . '_rejected_on_refunded_round', [
            'context' => $context,
            'game_round_id' => $betRecord->bet_id,
            'player_code' => $this->account->username,
        ]);

        return $this->gameRoundRefundedResponse($currentBalance);
    }

    /**
     * 這筆原交易是否已經被撤銷過。
     *
     * `refund()` 的去重只比對取消投注請求自己的 `TransactionId`，被取消的原扣款（`OriginalTransactionId`）
     * 從不被標記；遊戲商換一個 `TransactionId` 重送同一筆取消投注時，`processCancelTransaction()` 會撈到
     * 同一筆原交易，再從注單扣一次金額並入帳（target/op_tickets#3099）。取消投注紀錄自己在 `info` 裡記著
     * 它取消的是哪一筆，據此判定即可。
     *
     * 先以 `game_round_id` 索引縮到同局（實測生產每局平均 2 列、`type=9` 全表 215 列），再於 PHP 端比對
     * `info` 內的 `OriginalTransactionId`。`info` 是 TEXT 存 JSON，所以不下 SQL 的 JSON 函式或 LIKE，
     * 與 PlayTech（PTSW）的 `isBetAlreadyRefunded()` 同一寫法。
     *
     * 🔴 比對必須不分大小寫。上游 processCancelTransaction() 找原交易走的是 SQL
     * （`where('transaction_id', ...)`），欄位 collation 為 `utf8mb4_unicode_ci`（實查六站），即大小寫不敏感。
     * 這裡若用 `===` 做 byte 比對，遊戲商送 `abc123` 就會在上游命中原交易、在這裡比不中既有的取消投注
     * 紀錄，於是閘門放行、再退一次。兩端必須用同一套判定，同 PlayTech（PTSW）`isBetAlreadyRefunded()` 的
     * `strcasecmp`（`GameRoundLock::key()` 也是為了同一個理由先 `mb_strtolower`）。
     *
     * ⚠️ **本查詢是一般讀，也沒有取同局 named lock，是否足以避開舊快照「未驗證，需人工確認」。**
     * 同類守衛的其他寫法有兩種：交易內對判定查詢下 `lockForUpdate()`（SpadegamingSW、RGSW），或交易外
     * 先取同局 named lock（PlayTech 的 `GameRoundLock`）。本 Controller 另有兩處 `lockForUpdate()`
     * 鎖其他對象，**可能**已間接涵蓋，但未做併發驗證，不宜據此推論安全。
     * 快照與鎖順序的完整說明見類別 {@see BaseController} 的 docblock。
     */
    private function findCancelForOriginalTransaction(string $gameRoundId, string $originalTransactionId): ?TTHSWDealRecord
    {
        return TTHSWDealRecord::query()
            ->where('game_round_id', $gameRoundId)
            ->where('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
            ->get()
            ->first(static function (TTHSWDealRecord $record) use ($originalTransactionId): bool {
                $info = json_decode((string) $record->info, true);
                $recorded = is_array($info) ? ($info['OriginalTransactionId'] ?? null) : null;

                return (is_string($recorded) || is_int($recorded))
                    && strcasecmp((string) $recorded, $originalTransactionId) === 0;
            });
    }

    /**
     * 建立投注記錄
     */
    private function createBetRecord(array $data, string $currentBalance): TTHSWBetRecord
    {
        $betRecord = new TTHSWBetRecord;
        $betRecord->vendor_info_id = $this->vendorInfo->id;
        $betRecord->username = $this->account->username;
        $betRecord->bet_id = $data['GameRoundId'];
        $betRecord->game_code = $data['GameCode'] ?? '';
        $amount = $this->convertAmountByRate($data['Amount']);
        $betRecord->bet = $amount;
        $betRecord->valid_bet = $amount;
        $betRecord->win = '0.0000';
        $betRecord->win_lose = Common::numSub('0', $amount, 4);
        $betRecord->bonus = '0.0000';
        $betRecord->jackpot = '0.0000';
        $betRecord->rolling_chip = '0.0000';
        $betRecord->bet_time = $this->transactionDateWithoutFraction($data);
        $betRecord->status = BetRecordStatusEnum::PENDING;
        $betRecord->info = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $betRecord;
    }

    /**
     * 建立 PromoWin 記錄
     */
    private function createPromoWinRecord(array $data): TTHSWBetRecord
    {
        $promoRecord = new TTHSWBetRecord;
        $promoRecord->vendor_info_id = $this->vendorInfo->id;
        $promoRecord->username = $this->account->username;
        $promoRecord->bet_id = $data['GameRoundId'];
        $promoRecord->game_code = $data['GameCode'] ?? '';
        $promoRecord->bet = '0.0000';
        $promoRecord->valid_bet = '0.0000';
        $amount = $this->convertAmountByRate($data['Amount']);
        $promoRecord->win = $amount;
        $promoRecord->win_lose = $amount;
        $promoRecord->bonus = '0.0000';
        $promoRecord->jackpot = '0.0000';
        $promoRecord->rolling_chip = '0.0000';
        $promoRecord->bet_time = $this->transactionDateWithoutFraction($data);
        $promoRecord->payout_time = $this->transactionDateWithoutFraction($data);
        $promoRecord->status = BetRecordStatusEnum::PENDING;
        $promoRecord->info = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $promoRecord;
    }

    /**
     * 建立 Wallet 記錄
     *
     * @param  array  $data  請求資料
     * @param  TransactionTypeEnum  $type  交易類型
     */
    private function createWalletRecord(array $data, TransactionTypeEnum $type): TTHSWBetRecord
    {
        $walletRecord = new TTHSWBetRecord;
        $walletRecord->vendor_info_id = $this->vendorInfo->id;
        $walletRecord->username = $this->account->username;
        $walletRecord->bet_id = $data['GameRoundId'];
        $walletRecord->game_code = $data['GameCode'] ?? '';
        $amount = $this->convertAmountByRate($data['Amount']);

        // Wallet 扣款當投注（Joker 捕魚）
        if ($type->value === TransactionTypeEnum::DEBIT->value) {
            $walletRecord->bet = $amount;
            $walletRecord->valid_bet = $amount;
            $walletRecord->win = '0.0000';
            $walletRecord->win_lose = Common::numSub('0', $amount, 4);
        } elseif ($type->value === TransactionTypeEnum::CREDIT->value) {
            $walletRecord->bet = '0.0000';
            $walletRecord->valid_bet = '0.0000';
            $walletRecord->win = $amount;
            $walletRecord->win_lose = $amount;
        }

        $walletRecord->bonus = '0.0000';
        $walletRecord->jackpot = '0.0000';
        $walletRecord->rolling_chip = '0.0000';
        $walletRecord->bet_time = $this->transactionDateWithoutFraction($data);
        $walletRecord->payout_time = $this->transactionDateWithoutFraction($data);
        $walletRecord->status = BetRecordStatusEnum::PENDING;
        $walletRecord->info = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $walletRecord;
    }

    /**
     * 建立總注單記錄
     */
    private function createTotalBetRecord($betRecord, $transactionLogId, $walletDto): void
    {
        try {
            if (empty($transactionLogId)) {
                $transactionLogId = $this->findOpenSessionTransactionLogId($walletDto);
            }

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);
        } catch (Exception $e) {
            Log::error($this->actionPrefix . '_createTotalBetRecord_error', [
                'message' => $e->getMessage(),
                'betRecord' => $betRecord->toArray(),
            ]);

            throw $e;
        }
    }

    /**
     * transactionLogId 為 0 時（如輸局結算未異動真金錢包，changeBalance 不會回傳有效編號），
     * 補查該會員在本商戶目前遊戲中（status=999）的單錢包 session 紀錄，
     * 讓 total_bet_record 仍能連結回 transaction_logs，避免 SR14 明細查無資料。
     */
    private function findOpenSessionTransactionLogId($walletDto): int
    {
        if ($walletDto->isMultipleWallet) {
            $log = WalletTransactionLog::where('member_id', $this->member->id)
                ->where('vendor_id', $this->vendorId)
                ->where('wallet_type_id', WalletEnum::CODE_GAME)
                ->where('type', WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE)
                ->where('status', 999)
                ->orderBy('id', 'DESC')
                ->first();
        } else {
            $log = TransactionLog::where('member_id', $this->member->id)
                ->where('vendor_id', $this->vendorId)
                ->where('type', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE)
                ->where('status', 999)
                ->orderBy('id', 'DESC')
                ->first();
        }

        return $log->id ?? 0;
    }

    /**
     * 建立交易記錄到 tthsw_deal_records
     *
     * @param  string  $startBalance  交易前系統餘額
     * @param  string  $endBalance  交易後系統餘額
     */
    private function createDealRecord(array $data, string $action, string $startBalance, string $endBalance): void
    {
        $dealRecord = new TTHSWDealRecord;
        $dealRecord->game_code = $data['GameCode'];
        $dealRecord->game_round_id = $data['GameRoundId'];
        $dealRecord->transaction_id = $data['TransactionId'];
        $dealRecord->transaction_date = $this->transactionDateWithoutFraction($data);
        $dealRecord->amount = $this->convertAmountByRate($data['Amount'] ?? '0');
        $dealRecord->start_balance = $this->convertBalanceByRate($startBalance);
        $dealRecord->end_balance = $this->convertBalanceByRate($endBalance);
        $dealRecord->type = $data['Type'];
        $dealRecord->action = $action;
        $dealRecord->info = json_encode($data, JSON_UNESCAPED_UNICODE);
        $dealRecord->save();
    }

    /**
     * 回應期限（毫秒）
     */
    private function responseDeadlineMs(): int
    {
        $deadline = $this->vendorConfig['RESPONSE_DEADLINE_MS'] ?? null;

        return is_numeric($deadline) && (int) $deadline > 0 ? (int) $deadline : self::RESPONSE_DEADLINE_MS;
    }

    /**
     * 鎖定錢包後檢查回應期限，逾期即拋例外讓交易 rollback
     */
    private function lockWallets(): MemberWalletData
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->responseDeadline?->assert();

        return $walletDto;
    }

    // ==================== 回應方法 ====================

    /**
     * 成功回應
     */
    private function successResponse(array $value): JsonResponse
    {
        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'message' => ErrorCodeEnum::SUCCESS->getMessage(),
            'value' => $value,
        ]);
    }

    /**
     * 錯誤回應
     */
    private function errorResponse(ErrorCodeEnum $errorCode, string $message = ''): JsonResponse
    {
        return response()->json([
            'code' => $errorCode->value,
            'message' => $message ?: $errorCode->getMessage(),
            'value' => null,
        ]);
    }

    /**
     * 錯誤回應（附帶餘額）
     */
    private function errorResponseWithBalance(ErrorCodeEnum $errorCode, string $message, string $currentBalance): JsonResponse
    {
        $currentBalance = $this->convertBalanceByRate($currentBalance);

        return response()->json([
            'code' => $errorCode->value,
            'message' => $message ?: $errorCode->getMessage(),
            'value' => [
                'CurrentBalance' => $currentBalance,
            ],
        ]);
    }

    /**
     * 回應期限逾時（交易已 rollback）
     */
    private function responseDeadlineExceededResponse(ResponseDeadlineException $e, string $action, array $data): JsonResponse
    {
        Log::warning($this->actionPrefix . '_response_deadline_exceeded', [
            'action' => $action,
            'elapsed_ms' => $e->elapsedMs,
            'deadline_ms' => $e->deadlineMs,
            'player_code' => $data['PlayerCode'] ?? null,
            'game_round_id' => $data['GameRoundId'] ?? null,
            'transaction_id' => $data['TransactionId'] ?? null,
            'type' => $data['Type'] ?? null,
        ]);

        return $this->errorResponse(ErrorCodeEnum::ERROR, 'Response deadline exceeded');
    }

    /**
     * 成功交易回應
     */
    private function successTransactionResponse(TTHSWBetRecord $record, string $startBalance, string $currentBalance, string $transactionId): JsonResponse
    {
        $startBalance = $this->convertBalanceByRate($startBalance);
        $currentBalance = $this->convertBalanceByRate($currentBalance);

        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'message' => ErrorCodeEnum::SUCCESS->getMessage(),
            'value' => [
                'GameRoundId' => $record->bet_id,
                'TransactionId' => $transactionId,
                'ExternalTransactionId' => (string) $record->id,
                'ProcessAt' => $record->bet_time ?? now()->format('Y-m-d\TH:i:s.vP'),
                'StartBalance' => $startBalance,
                'EndBalance' => $currentBalance,
                'CurrentBalance' => $currentBalance,
            ],
        ]);
    }

    /**
     * 交易重複回應（返回原交易結果）
     */
    private function transactionDuplicatedResponse(string $roundId, string $transactionId, TransactionTypeEnum $type): JsonResponse
    {
        $record = $this->findBetRecordByGameRound($roundId);
        $dealRecord = TTHSWDealRecord::where('game_round_id', $roundId)
            ->where('transaction_id', $transactionId)
            ->where('type', $type->value)
            ->first();

        $startBalance = (string) ($dealRecord?->start_balance ?? '0.0000');
        $endBalance = (string) ($dealRecord?->end_balance ?? '0.0000');

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $currentBalance = $walletDto->getTotalBalance();
        $currentBalance = $this->convertBalanceByRate($currentBalance);

        return response()->json([
            'code' => ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->value,
            'message' => ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->getMessage(),
            'value' => [
                'GameRoundId' => $roundId,
                'TransactionId' => $transactionId,
                'ExternalTransactionId' => (string) $record->id,
                'ProcessAt' => $record->bet_time ?? now()->format('Y-m-d\TH:i:s.vP'),
                'StartBalance' => $startBalance,
                'EndBalance' => $endBalance,
                'CurrentBalance' => $currentBalance,
            ],
        ]);
    }

    /**
     * 同一筆原交易已被撤銷過時的回應。
     *
     * 與 transactionDuplicatedResponse 的差別：三方這次帶的是**新的** TransactionId，所以 echo 回它送來的值，
     * 而 StartBalance／EndBalance 取自既有那筆撤單當時的紀錄——符合「重複請求回第一次的結果」。
     *
     * 沒有改用語意更貼切的 GAME_ROUND_TRANSACTION_REFUNDED（503）：TTHSW 在 docs/integrations/vendors/
     * 底下沒有三方文件，無從確認三方收到 503 會不會持續重送；502 是現行同 TransactionId 重送時就在回的碼，
     * 線上行為已知。要改用 503 須先向 TTH 確認。
     *
     * ExternalTransactionId 一律取**注單** id，與 successTransactionResponse()／transactionDuplicatedResponse()
     * 同一個命名空間。兩張表都是自增整數、值域必然重疊，若這裡改回 deal record id，三方拿同一個欄位對帳
     * 會指到不同表的列。
     */
    private function originalTransactionCanceledResponse(
        array $data,
        TTHSWDealRecord $existingCancel,
        TTHSWBetRecord $roundRecord,
        string $currentBalance
    ): JsonResponse {
        return response()->json([
            'code' => ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->value,
            'message' => ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->getMessage(),
            'value' => [
                'GameRoundId' => $data['GameRoundId'],
                'TransactionId' => $data['TransactionId'],
                'ExternalTransactionId' => (string) $roundRecord->id,
                'ProcessAt' => $roundRecord->bet_time ?? now()->format('Y-m-d\TH:i:s.vP'),
                'StartBalance' => (string) $existingCancel->start_balance,
                'EndBalance' => (string) $existingCancel->end_balance,
                'CurrentBalance' => $this->convertBalanceByRate($currentBalance),
            ],
        ]);
    }

    /**
     * 餘額不足回應
     */
    private function insufficientFundResponse(string $currentBalance): JsonResponse
    {
        $currentBalance = $this->convertBalanceByRate($currentBalance);

        return response()->json([
            'code' => ErrorCodeEnum::INSUFFICIENT_FUND->value,
            'message' => ErrorCodeEnum::INSUFFICIENT_FUND->getMessage(),
            'value' => [
                'CurrentBalance' => $currentBalance,
            ],
        ]);
    }

    /**
     * 遊戲回合已退款回應
     */
    private function gameRoundRefundedResponse(string $currentBalance): JsonResponse
    {
        $currentBalance = $this->convertBalanceByRate($currentBalance);

        return response()->json([
            'code' => ErrorCodeEnum::GAME_ROUND_REFUNDED->value,
            'message' => ErrorCodeEnum::GAME_ROUND_REFUNDED->getMessage(),
            'value' => [
                'StartBalance' => $currentBalance,
                'EndBalance' => $currentBalance,
                'CurrentBalance' => $currentBalance,
            ],
        ]);
    }

    /**
     * 轉換餘額為商戶幣值（乘以匯率），小數位數依商戶配置 BALANCE_SCALE，未配置為 2
     */
    private function convertBalanceByRate(string $balance): string
    {
        $scale = $this->vendorConfig['BALANCE_SCALE'] ?? 2;
        $scale = is_numeric($scale) ? (int) $scale : 2;

        return Common::numMul($balance, $this->vendorInfo->rate, $scale);
    }

    /**
     * 轉換金額為系統金額（除以匯率）
     */
    private function convertAmountByRate(string $amount): string
    {
        if ($this->vendorInfo->rate == 0) {
            return $amount;
        }

        $convertedAmount = Common::numDiv($amount, $this->vendorInfo->rate, 4);

        return (string) $convertedAmount;
    }
}
