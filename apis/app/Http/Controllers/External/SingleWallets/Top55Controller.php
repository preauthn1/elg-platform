<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\Top55swAccount;
use App\Vendors\Models\Top55swBetRecord;
use App\Vendors\Top55\BetRecordStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * @property Top55swAccount $account
 *
 * @knowledge rules/vendor-top55
 */
class Top55Controller extends BaseController
{
    protected string $vendorCode = 'Top55SW';

    protected string $accountClass = Top55swAccount::class;

    protected string $betRecordClass = Top55swBetRecord::class;

    protected string $actionPrefix = 'top55_';

    protected array $vendorConfig; // 商戶配置

    protected string $traceId; // 錯誤的追蹤碼

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        $this->traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            $input = $request->all();

            // 驗證必要的請求參數
            $validator = Validator::make($input, [
                'userCode' => 'required|string',
            ]);

            if ($validator->fails()) {
                $errorMsg = $validator->errors()->first();

                return response()->json([
                    'success' => false,
                    'message' => $errorMsg,
                    'errorCode' => 1,
                ]);
            }

            // 檢查玩家帳號是否存在並取得商戶資訊
            $this->getPlayerByUsername($input['userCode']);

            // 驗證授權
            $this->vaildateAuthorization();

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new ApiException('Invalid IP');
            }

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response
            return $response;
        } catch (ApiException $e) {
            // 可預期的業務拒絕（帳號異常、驗證失敗、餘額不足、重複交易…）：
            // 只記 warning 並回三方錯誤格式，不往外拋，避免觸發 TG 告警
            Log::warning($this->actionPrefix . 'rejected', [
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errorCode' => $e->getCode() ?: 1,
            ]);
        } catch (Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';

            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * 查詢玩家帳號
     */
    public function getPlayerByUsername(string $username): void
    {
        // 先載入相關關聯，方便檢查各項狀態
        $account = $this->accountClass::query()
            ->with(['vendorInfo', 'member'])
            ->where('username', $username)
            ->first();

        if (!$account) {
            Log::warning(sprintf('[%s] The player not found: %s', $this->vendorCode, $username));
            throw new ApiException('Account not found');
        }

        if ($account->status !== 1) {
            Log::warning(sprintf('[%s] The player has been deactivated: %s', $this->vendorCode, $username));
            throw new ApiException('Account blocked');
        }

        // 驗證帳號狀態
        $this->validateAccountStatus($account, $username);

        // 設定屬性
        $this->account = $account;
        $this->member = $account->member;
        $this->vendorId = $account->vendorInfo->vendor_id;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorConfig = $account->vendorInfo->info;
    }

    /**
     * 驗證帳號狀態
     */
    private function validateAccountStatus(Top55swAccount $account, string $username): void
    {
        $reasons = [];

        if ($account->status != 1) {
            $reasons[] = 'account inactive';
        }

        if (empty($account->vendorInfo) || $account->vendorInfo->status != 1) {
            $reasons[] = 'vendorInfo inactive or missing';
        }

        if (empty($account->member) || $account->member->status != 1) {
            $reasons[] = 'member inactive or missing';
        }

        if (!empty($reasons)) {
            Log::warning(sprintf('[%s] The player exists but verification is invalid.', $this->vendorCode), [
                'username' => $username,
                'reasons' => $reasons,
            ]);

            throw new ApiException('Invalid player account');
        }
    }

    private function vaildateAuthorization()
    {
        $authorization = request()->header('Authorization');

        if (empty($authorization)) {
            throw new ApiException('Authorization header is missing');
        }

        $base64 = base64_encode($this->vendorInfo->info['clientId'] . ':' . $this->vendorInfo->info['clientSecret']);
        if ($authorization != 'Basic ' . $base64) {
            throw new ApiException('Authorization header is invalid');
        }
    }

    public function balance()
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = $walletDto->getTotalBalance();

        $vendorBalance = (float) Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'success' => true,
            'message' => $vendorBalance,
            'errorCode' => 0,
        ]);
    }

    /**
     * 請求範例
     * 玩家輸只回調一次 投注: amount = -10，isFinish = true，isCancel = false
     * 玩家贏回調兩次 投注: amount = 10，isFinish = true，isCancel = false， 派彩: amount = 20，isFinish = true，isCancel = false
     * 取消單 isCancel = true
     */
    public function transaction(Request $request)
    {
        $params = $request->all();
        $balance = $this->execTransaction($params);

        return response()->json([
            'success' => true,
            'message' => $balance,
            'errorCode' => 0,
        ]);
    }

    public function batchTransactions(Request $request)
    {
        $params = $request->all();

        $balance = 0;
        foreach ($params['transactions'] as $transaction) {
            $balance = $this->execTransaction($transaction);
        }

        return response()->json([
            'success' => true,
            'message' => $balance,
            'errorCode' => 0,
        ]);
    }

    private function execTransaction(array $params): string
    {
        return DB::transaction(function () use ($params) {
            // bet_id 是字串欄位，傳整數比較會讓 MySQL 逐列轉型、用不到唯一索引而全表掃描
            $betId = (string) $params['historyId'];
            $amount = (float) Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
            $createdAt = Carbon::parse($params['createdAt']);

            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            if ($amount < 0) {

                $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gameCode']);
                if (!$ableToBet) {
                    throw new ApiException('Account could not bet yet', 1);
                }

                if (Common::numCompare($balance, abs($amount)) < 0) {
                    throw new ApiException('Insufficient balance', 2);
                }

                $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

                if ($oldBetRecord !== null) {
                    throw new ApiException('Duplicate transaction', 1);
                }

                $betRecord = new $this->betRecordClass([
                    'member_id' => $this->member->id,
                    'username' => $params['userCode'],
                    'bet_id' => $betId,
                    'vendor_code' => $params['vendorCode'],
                    'game_code' => $params['gameCode'],
                    'bet' => abs($amount),
                    'valid_bet' => abs($amount),
                    'payout' => 0,
                    'win_lose' => $amount,
                    'rolling_chips' => 0,
                    'bet_time' => $createdAt,
                    'payout_time' => ($params['isFinished'] === true) ? $createdAt : null,
                    'status' => ($params['isFinished'] === true) ? BetRecordStatusEnum::SETTLEMENT->value : BetRecordStatusEnum::BET->value,
                    'info' => [$params],
                ]);
            } else {
                $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

                if (!$oldBetRecord) {
                    throw new ApiException('Bet transaction not found', 1);
                }

                if ($oldBetRecord->status == BetRecordStatusEnum::SETTLEMENT->value) {
                    throw new ApiException('Bet record already settled', 1);
                }

                if ($oldBetRecord->status == BetRecordStatusEnum::CANCEL->value) {
                    throw new ApiException('Bet record already canceled', 1);
                }

                $betRecord = clone $oldBetRecord;
                $betRecord->payout = $amount;
                $betRecord->win_lose = Common::numSub($betRecord->payout, $betRecord->bet);
                $betRecord->payout_time = $createdAt;
                $betRecord->status = ($params['isCanceled'] === false) ? BetRecordStatusEnum::SETTLEMENT->value : BetRecordStatusEnum::CANCEL->value;
                $betRecord->info = array_merge($betRecord->info, [$params]);
            }

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            if ($betRecord->status == BetRecordStatusEnum::SETTLEMENT->value) {
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);
            }

            return $walletDto->getTotalBalance();
        });
    }
}
