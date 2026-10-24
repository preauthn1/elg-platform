<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\Rcb988Account;
use App\Vendors\Models\Rcb988BetRecord;
use App\Vendors\Rcb988\Enums\ActionEnum;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use App\Vendors\Rcb988\Rcb988Exception;
use App\Vendors\Rcb988\Rcb988VendorInfoDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @knowledge rules/vendor-rcb988
 */
class Rcb988Controller extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::RCB988->value;

    protected string $accountClass = Rcb988Account::class;

    protected string $betRecordClass = Rcb988BetRecord::class;

    protected string $actionPrefix = 'rcb988_get_request_';

    protected Rcb988VendorInfoDetail $infoDetail;

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();

            $input = json_decode($request->input('message'), true);
            $reqAction = (isset($input['action']) ? $input['action'] : null);
            $action = $this->actionPrefix . $reqAction;

            $this->logRequest($request, $action);
            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new Rcb988Exception(ErrorCodeEnum::FAIL, 'IP not in whitelist');
            }

            $this->setUpVendorAccount(thirdPartyUsername: $this->getUsername($request));

            // 共用組裝只依外鍵取商戶；改版前的查詢限定本遊戲商且 status=1，這裡補回同一道條件與回應
            if ((int) $this->vendorInfo->vendor_id !== $this->vendorId || (int) $this->vendorInfo->status !== 1) {
                throw new Rcb988Exception(ErrorCodeEnum::FAIL, 'Can not find corresponding vendor info');
            }
            $this->infoDetail = Rcb988VendorInfoDetail::from($this->vendorInfo->info);

            // 驗證簽名
            if ($this->checkSign($request) === false) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_CERTIFICATE, 'Sign error');
            }

            // 呼叫真正的 controller 方法
            $response = DB::transaction(function () use ($method, $parameters) {
                $response = parent::callAction($method, $parameters);

                return $response;
            });

            // 後置處理：例如包裝 response

            return $response;
        } catch (Rcb988Exception $e) {
            return $this->errorResponse($e->errorCode->value, $e->getMessage());
        } catch (VendorAccountException|MemberNotFoundException $e) {
            return $this->errorResponse(ErrorCodeEnum::ACCOUNT_NOT_EXISTS->value, 'User not found');
        } catch (VendorNotFoundException $e) {
            return $this->errorResponse(ErrorCodeEnum::FAIL->value, 'Can not find corresponding vendor info');
        } catch (BetException $e) {
            return $this->errorResponse(ErrorCodeEnum::ACCOUNT_LOCKED->value, ErrorCodeEnum::ACCOUNT_LOCKED->name);
        } catch (Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';
            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->errorResponse(ErrorCodeEnum::FAIL->value, $e->getMessage());
        }
    }

    /**
     * 統一入口
     */
    public function entrance(Request $request): JsonResponse
    {
        $message = json_decode($request->input('message'), true);
        $messageAction = $message['action'] ?? '';

        $action = ActionEnum::tryFrom($messageAction);
        if (!$action) {
            throw new Rcb988Exception(ErrorCodeEnum::INVALID_PARAMETERS, 'Invalid action');
        }

        if ($action == ActionEnum::GET_BALANCE) {
            return $this->getBalance($request);
        } else {
            $txns = $message['txns'] ?? [];
            $return = null;
            foreach ($txns as $txn) {
                $txn['action'] = $messageAction;
                $return = match ($action) {
                    ActionEnum::BET => $this->bet($txn, $action),
                    ActionEnum::ADJUST_BET => $this->bet($txn, $action),
                    ActionEnum::SETTLE => $this->settle($txn, $action),
                    ActionEnum::RESETTLE => $this->settle($txn, $action),
                    ActionEnum::CANCEL_BET => $this->cancel($txn, $action),
                    ActionEnum::UNSETTLE => $this->cancel($txn, $action),
                    ActionEnum::VOID_BET => $this->cancel($txn, $action),
                    ActionEnum::VOID_SETTLE => $this->cancel($txn, $action),
                    ActionEnum::UNVOID_BET => $this->unCancel($txn, $action),
                    ActionEnum::UNVOID_SETTLE => $this->unCancel($txn, $action), // @phpstan-ignore match.alwaysTrue
                    default => throw new Rcb988Exception(ErrorCodeEnum::INVALID_PARAMETERS, 'Invalid action'),
                };
            }
        }

        return $return;
    }

    /**
     * 取得用戶名
     * getBalance 時，用 userId 取得用戶名
     * 其他時，用 txns 中的 userId 取得用戶名
     * 如果 userId 不一致，則返回錯誤
     */
    private function getUsername(Request $request): string
    {
        $message = json_decode($request->input('message'), true);
        $action = $message['action'] ?? '';
        if ($action == ActionEnum::GET_BALANCE->value) {
            $username = $message['userId'] ?? '';
        } else {
            $txns = $message['txns'] ?? [];
            $username = '';
            foreach ($txns as $txn) {
                if ($username != '' && $username != $txn['userId']) {
                    throw new Rcb988Exception(ErrorCodeEnum::INVALID_USER_ID, 'User ID mismatch');
                }
                $username = $txn['userId'];
            }
        }
        if ($username == '') {
            throw new Rcb988Exception(ErrorCodeEnum::INVALID_USER_ID, 'User ID not found');
        }

        return $username;
    }

    /**
     * action 對應的投注紀錄狀態
     */
    private function getStatus(ActionEnum $action): BetRecordStatusEnum
    {
        return match ($action) {
            ActionEnum::BET => BetRecordStatusEnum::BET,
            ActionEnum::ADJUST_BET => BetRecordStatusEnum::BET,
            ActionEnum::SETTLE => BetRecordStatusEnum::SETTLE,
            ActionEnum::RESETTLE => BetRecordStatusEnum::SETTLE,
            ActionEnum::CANCEL_BET => BetRecordStatusEnum::CANCEL,
            ActionEnum::UNSETTLE => BetRecordStatusEnum::CANCEL,
            ActionEnum::VOID_BET => BetRecordStatusEnum::VOID_BET,
            ActionEnum::VOID_SETTLE => BetRecordStatusEnum::VOID_SETTLE,
            ActionEnum::UNVOID_BET => BetRecordStatusEnum::BET,
            ActionEnum::UNVOID_SETTLE => BetRecordStatusEnum::SETTLE,
            default => throw new \ValueError("Unexpected action: {$action->value}"),
        };
    }

    /**
     * 查詢餘額
     */
    public function getBalance(Request $request)
    {
        if ($this->account->status == 0) {
            throw new Rcb988Exception(ErrorCodeEnum::ACCOUNT_NOT_EXISTS, 'User not found');
        }
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    /**
     * 投注或調整投注
     *
     * @throws Rcb988Exception
     */
    private function bet(array $input, ActionEnum $action): JsonResponse
    {
        if ($this->account->status == 0) {
            throw new Rcb988Exception(ErrorCodeEnum::ACCOUNT_NOT_EXISTS, 'User not found');
        }

        // 先鎖會員列再做任何一般讀：verifyBettability 會查表，放在鎖前會把 RR 快照定在等鎖之前（target/tickets#4297）
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

        $this->verifyBettability((string) $input['gameCode']);

        $bet = Common::numDiv($input['betAmount'], $this->vendorInfo->rate);
        $win = '0';
        if (Common::numCompare($bet, 0) < 0) {
            throw new Rcb988Exception(ErrorCodeEnum::INVALID_BET, 'Bet amount should be positive');
        }

        if (Common::numCompare($walletDto->getTotalBalance(), $bet) < 0) {
            throw new Rcb988Exception(ErrorCodeEnum::NOT_ENOUGH_BALANCE, 'Wallet balance not enough');
        }
        // 取得投注紀錄
        $betId = $input['platformTxId'];
        $oldBetRecord = Rcb988BetRecord::where('bet_id', $betId)->first();

        // 投注時，如果投注紀錄已存在，則返回錯誤
        if ($action == ActionEnum::BET && $oldBetRecord) {
            throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_ALREADY_OPERATED, 'Bet record is already bet');
        }

        // 調整投注時，如果投注紀錄不存在，則返回錯誤
        if ($action == ActionEnum::ADJUST_BET && !$oldBetRecord) {
            throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_NOT_EXISTS, 'Bet not found');
        }

        // 投注紀錄已存在
        if ($oldBetRecord) {
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win_lose = Common::numSub($win, $bet);
        } else {
            $betRecord = new Rcb988BetRecord([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'vendor_username' => $this->account->vendor_username,
                'bet_time' => Carbon::parse($input['betTime'])->toSystemTime()->toDateTimeString(),
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => Common::numSub($win, $bet),
                'status' => BetRecordStatusEnum::BET,
                'game_code' => $input['gameCode'],
                'rolling_chip' => 0,
                'jackpot' => 0,
                'session_token' => $this->account->session_token,
                'info' => '[]',
            ]);
        }
        // 紀錄請求資料
        $betRecord->info = $this->appendParamsToInfo($betRecord->info, $input);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

        $betRecord->save();

        // 寫入全部遊戲紀錄
        // $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        // $totalBetRecordService->create($betRecord, $transactionLogId);

        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    /**
     * 派彩結算或重新結算
     *
     * @throws Rcb988Exception
     */
    private function settle(array $input, ActionEnum $action): JsonResponse
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $win = Common::numDiv($input['winAmount'], $this->vendorInfo->rate);
        if (Common::numCompare($win, 0) < 0) {
            throw new Rcb988Exception(ErrorCodeEnum::INVALID_AMOUNT, 'Settle amount should be positive');
        }

        $betId = $input['platformTxId'];
        $oldBetRecord = Rcb988BetRecord::lockForUpdate()
            ->where('bet_id', $betId)
            ->first();

        if (empty($oldBetRecord)) {
            throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_NOT_EXISTS, 'Bet not found');
        }
        if ($action == ActionEnum::SETTLE) {
            if ($oldBetRecord->status != BetRecordStatusEnum::BET->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not bet');
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::SETTLE->value) {
                throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_ALREADY_OPERATED, 'Bet record is already settled');
            }
        }
        if ($action == ActionEnum::RESETTLE) {
            if ($oldBetRecord->status != BetRecordStatusEnum::SETTLE->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not settle');
            }
        }

        $betRecord = clone $oldBetRecord;
        $betRecord->status = BetRecordStatusEnum::SETTLE->value;
        $betRecord->win_lose = Common::numAdd($win, $betRecord->win_lose);
        $betRecord->payout_time = Carbon::parse($input['txTime'])->toSystemTime()->toDateTimeString();

        // 紀錄請求資料
        $betRecord->info = $this->appendParamsToInfo($betRecord->info, $input);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

        $betRecord->save();

        // 寫入全部遊戲紀錄
        $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        $totalBetRecordService->create($betRecord, $transactionLogId);

        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    /**
     * 取消投注或結算
     *
     * @throws Rcb988Exception
     */
    private function cancel(array $input, ActionEnum $action): JsonResponse
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

        $betId = $input['platformTxId'];
        // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
        // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
        // 本路徑查無時會建空注單，正是上述「讀不到再 INSERT」的型態。bet_id 屬單一玩家，
        // 上方 getMemberLockedWallets() 的會員列鎖已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下。
        $oldBetRecord = Rcb988BetRecord::query()
            ->where('bet_id', $betId)
            ->first();

        if (empty($oldBetRecord)) {
            if ($action == ActionEnum::CANCEL_BET) {
                // 如果投注紀錄不存在，則創建一筆金額為0的投注紀錄
                // http://example.invalid:1688/target/tickets/-/issues/3329
                $now = Carbon::now()->toSystemTime()->toDateTimeString();
                $betRecord = new Rcb988BetRecord([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'bet_id' => $betId,
                    'vendor_username' => $this->account->vendor_username,
                    'bet_time' => $now,
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win_lose' => 0,
                    'status' => $this->getStatus($action)->value,
                    'game_code' => $input['gameCode'],
                    'rolling_chip' => 0,
                    'jackpot' => 0,
                    'session_token' => $this->account->session_token,
                    'info' => '[]',
                ]);
                $betRecord->info = $this->appendParamsToInfo($betRecord->info, $input);
                $betRecord->save();
                $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

                return $this->response($balance);
            } else {
                throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_NOT_EXISTS, 'Bet not found');
            }
        }
        if (in_array($action, [ActionEnum::CANCEL_BET, ActionEnum::VOID_BET])) {
            if ($oldBetRecord->status != BetRecordStatusEnum::BET->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not bet');
            }
        }
        if (in_array($action, [ActionEnum::UNSETTLE, ActionEnum::VOID_SETTLE])) {
            if ($oldBetRecord->status != BetRecordStatusEnum::SETTLE->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not settle');
            }
        }
        $betRecord = clone $oldBetRecord;
        $betRecord->status = $this->getStatus($action)->value;
        $betRecord->bet = 0;
        $betRecord->valid_bet = 0;
        $betRecord->win_lose = 0;

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord, false);

        // 更新全部遊戲紀錄, 注意要寫入投注額為0的紀錄
        if ($action == ActionEnum::UNSETTLE || $action == ActionEnum::VOID_SETTLE) {
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);
        }

        // 紀錄請求資料
        $betRecord->info = $this->appendParamsToInfo($betRecord->info, $input);

        // 保留原投注紀錄的資料
        $betRecord->bet = $oldBetRecord->bet;
        $betRecord->valid_bet = $oldBetRecord->valid_bet;
        $betRecord->win_lose = $oldBetRecord->win_lose;
        $betRecord->save();

        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    /**
     * 還原取消
     *
     * @throws Rcb988Exception
     */
    private function unCancel(array $input, ActionEnum $action): JsonResponse
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

        $betId = $input['platformTxId'];
        $oldBetRecord = Rcb988BetRecord::lockForUpdate()
            ->where('bet_id', $betId)
            ->first();

        if (empty($oldBetRecord)) {
            throw new Rcb988Exception(ErrorCodeEnum::TX_CODE_NOT_EXISTS, 'Bet not found');
        }
        if (in_array($action, [ActionEnum::UNVOID_BET])) {
            if ($oldBetRecord->status != BetRecordStatusEnum::VOID_BET->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not void bet');
            }
        }
        if (in_array($action, [ActionEnum::UNVOID_SETTLE])) {
            if ($oldBetRecord->status != BetRecordStatusEnum::VOID_SETTLE->value) {
                throw new Rcb988Exception(ErrorCodeEnum::INVALID_TRANSACTION_STATUS, 'Bet record status is not void settle');
            }
        }

        $betRecord = clone $oldBetRecord;
        $betRecord->status = $this->getStatus($action)->value;

        // 將舊的投注額設為0, 但不更新DB, 用來修改餘額
        $oldBetRecord->bet = 0;
        $oldBetRecord->valid_bet = 0;
        $oldBetRecord->win_lose = 0;

        // 紀錄請求資料
        $betRecord->info = $this->appendParamsToInfo($betRecord->info, $input);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

        $betRecord->save();

        // 更新全部遊戲紀錄
        if ($action == ActionEnum::UNVOID_SETTLE) {
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);
        }

        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    private function errorResponse(string|int $code, string $msg): JsonResponse
    {
        return response()->json([
            'status' => $code,
            'desc' => $msg,
        ]);
    }

    private function response(string $balance): JsonResponse
    {
        $balance = (float) Common::numFloor($balance, 3);

        return response()->json([
            'status' => ErrorCodeEnum::SUCCESS->value,
            'desc' => 'Success',
            'balance' => $balance,
            'balanceTs' => now()->format('Y-m-d\TH:i:s.vP'),  // 不要用 toIso8601String()，因為他要毫秒
            'userId' => $this->account->username,
        ]);
    }

    private function checkSign(Request $request): bool
    {
        return $this->infoDetail->CLIENT_SECRET === $request->input('key');
    }
}
