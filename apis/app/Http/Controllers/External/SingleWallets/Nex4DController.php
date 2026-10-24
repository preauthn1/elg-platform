<?php

namespace App\Http\Controllers\External\SingleWallets;

use AllowDynamicProperties;
use App\Enums\General\ContextKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\VendorMaintenances;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\Nex4dswAccount;
use App\Vendors\Models\Nex4dswBetRecord;
use App\Vendors\Models\Nex4dswDealRecord;
use App\Vendors\Nex4DSW\BetRecordStatusEnum;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * @property Nex4dswAccount $account
 */
#[AllowDynamicProperties]
class Nex4DController extends BaseController
{
    protected string $vendorCode = 'NEX4DSW';

    protected int $vendorId = VendorIdEnum::Nex4DSW->value;

    protected string $accountClass = Nex4dswAccount::class;

    protected string $betRecordClass = Nex4dswBetRecord::class;

    protected string $dealRecordClass = Nex4dswDealRecord::class;

    protected string $actionPrefix = 'NEX4DSW_';

    protected array $vendorConfig; // 商戶配置

    protected string $traceId; // 錯誤的追蹤碼

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        $this->traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        // 初始化 dealRecord 變數以便在 catch 區塊中使用
        $dealRecord = null;

        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new ApiException('Invalid IP');
            }

            if ($request->has('betId') && $request->has('transId')) {
                // 紀錄 Deal Record
                $dealRecord = $this->dealRecordClass::create([
                    'bet_id' => $request->input('betId'),
                    'reference_no' => $request->input('transId'),
                    'action' => basename(url()->current()),
                    'trace_id' => $this->traceId,
                    'status' => 0,
                    'request' => Common::safeJsonEncode($request->all()),
                ]);
            }

            $input = $request->all();

            // 驗證必要的請求參數
            $validator = Validator::make($input, [
                'username' => 'required|string',
                'opCode' => 'required|string',
            ]);

            if ($validator->fails()) {
                $errorMsg = $validator->errors()->first();

                $dealRecord?->update([
                    'status' => -1,
                    'message' => mb_substr($errorMsg, 0, 50),
                ]);

                return response()->json([
                    'code' => -1,
                    'description' => $errorMsg,
                ]);
            }

            // 檢查遊戲是否維護
            if ($this->isVendorMaintenance() === true) {
                $dealRecord?->update([
                    'status' => -1,
                    'message' => 'System maintenance',
                ]);

                return response()->json([
                    'code' => -2,
                    'description' => 'System maintenance',
                ]);
            }

            // 檢查玩家帳號是否存在並取得商戶資訊
            try {
                $this->getPlayerByUsername($input['username']);
            } catch (Throwable $e) {
                $dealRecord?->update([
                    'status' => -1,
                    'message' => mb_substr($e->getMessage(), 0, 50),
                ]);

                return response()->json([
                    'code' => -4,
                    'description' => $e->getMessage(),
                ]);
            }

            // 檢查 OP_CODE
            if ($input['opCode'] !== $this->vendorConfig['OP_CODE']) {
                $dealRecord?->update([
                    'status' => -1,
                    'message' => 'Invalid opCode',
                ]);

                return response()->json([
                    'code' => -1,
                    'description' => 'Invalid opCode',
                ]);
            }

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 更新 Deal Record 狀態為成功
            if ($response instanceof JsonResponse) {
                $responseData = $response->getData(true); // 獲取數組格式的數據

                if (isset($responseData['code']) && $responseData['code'] === 0) {
                    $dealRecord?->update([
                        'status' => 1,
                    ]);
                }
            }

            // 後置處理：例如包裝 response
            return $response;
        } catch (Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';

            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => Common::formatTrace($e),
            ]);

            // 將異常信息記錄到 dealRecord 的 message 欄位
            if ($dealRecord) {
                $dealRecord->update([
                    'status' => -1,
                    'message' => mb_substr($e->getMessage(), 0, 50), // 限制長度為 50 字元
                ]);
            }

            throw $e;
        }
    }

    /**
     * ============================ 工具方法 ============================
     */

    /**
     * 查詢玩家帳號
     */
    private function getPlayerByUsername(string $username): void
    {
        // 先載入相關關聯，方便檢查各項狀態
        $account = $this->accountClass::query()
            ->with(['vendorInfo', 'member'])
            ->where('username', $username)
            ->first();

        if (!$account) {
            Log::warning(sprintf('[%s] The player not found: %s', $this->vendorCode, $username));
            throw new Exception('Account not found');
        }

        if ($account->status !== 1) {
            Log::warning(sprintf('[%s] The player has been deactivated: %s', $this->vendorCode, $username));
            throw new Exception('Account blocked');
        }

        // 驗證帳號狀態
        $this->validateAccountStatus($account, $username);

        // 設定屬性
        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorConfig = $account->vendorInfo->info;
    }

    /**
     * 驗證帳號狀態
     */
    private function validateAccountStatus(Nex4dswAccount $account, string $username): void
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

            throw new Exception('Invalid player account');
        }
    }

    /**
     * 將 +8 時區時間轉換為 UTC 時間
     *
     * @param  string  $dateTime  格式: Y-m-d H:i:s (UTC+8 時區)
     * @return string UTC 時間格式: Y-m-d H:i:s
     */
    private function convertToUtc(string $dateTime): string
    {
        try {
            // 將輸入的時間視為 UTC+8 時區，然後轉換為系統 UTC 時間
            return Carbon::parse($dateTime, 'Asia/Shanghai')
                ->toSystemTime()
                ->toDateTimeString();
        } catch (Exception $e) {
            // 如果解析失敗，記錄錯誤並返回原始值
            Log::error(sprintf('[%s] Failed to convert timezone', $this->vendorCode), [
                'dateTime' => $dateTime,
                'error' => $e->getMessage(),
            ]);

            return $dateTime;
        }
    }

    /**
     * 檢查遊戲商是否掛維護
     */
    private function isVendorMaintenance(): bool
    {
        $now = now()->toDateTimeString();

        $isMaintenance = VendorMaintenances::query()
            ->where('vendor_id', $this->vendorId)
            ->whereIn('status', [1, 2]) // 關閉類型，1:遊戲跟錢包都關閉、2:關閉遊戲、3：關閉錢包
            ->where('type', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();

        if (!$isMaintenance) {
            return false;
        }

        return true;
    }

    /**
     * 獲取用戶當前餘額並轉換為遊戲商幣值
     *
     * @return float 轉換後的遊戲商餘額
     */
    private function getVendorBalance(): float
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = $walletDto->getTotalBalance();

        return (float) Common::numMul($balance, $this->vendorInfo->rate, 4);
    }

    /**
     * 驗證安全令牌
     *
     * @param  array  $input  已驗證的資料
     * @param  string  $securityToken  客戶端提供的安全令牌
     * @return bool 驗證是否通過
     */
    private function validateSecurityToken(string $functionName, array $input, string $securityToken): bool
    {
        $expectedToken = '';

        if (in_array($functionName, ['PlayerBet', 'PlayerCancelBet'])) {
            // username + betAmount + transId + function + reqDateTime + opCode + secretKey
            $expectedToken = [REDACTED]'username'] . $input['betAmount'] . $input['transId'] . $functionName . $input['reqDateTime'] . $input['opCode'];
        }

        if (in_array($functionName, ['PlayerPayout', 'PlayerRevertPayout'])) {
            // username + payoutAmount + transId + function + reqDateTime + opCode + secretKey
            $expectedToken = [REDACTED]'username'] . $input['payoutAmount'] . $input['transId'] . $functionName . $input['reqDateTime'] . $input['opCode'];
        }

        $expectedToken .= $this->vendorConfig['SECRET_KEY'];

        if (md5($expectedToken) !== $securityToken) {
            Log::error(sprintf('[%s] Invalid security token: %s', $this->vendorCode, $expectedToken));

            return false;
        }

        return true;
    }

    /**
     * 統一驗證請求參數
     *
     * 包含：參數驗證、securityToken 驗證、重複交易檢查
     *
     * @param  array  $input  請求參數
     * @param  array  $rules  驗證規則
     * @param  string  $functionName  操作類型（用於日誌）
     * @return array|null 如果驗證失敗，返回錯誤 response 陣列；成功返回 null
     */
    private function validateRequest(array $input, array $rules, string $functionName = 'transaction'): ?array
    {
        // 1. 參數驗證
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return [
                'code' => -1,
                'description' => $validator->errors()->first(),
            ];
        }

        // 2. 檢查 securityToken
        if (!$this->validateSecurityToken($functionName, $validator->validated(), $input['securityToken'])) {
            return [
                'code' => -5,
                'description' => 'Invalid security token',
            ];
        }

        // 3. 查詢 DealRecord 是否已經存在相同的 transId（幂等性檢查）
        if (isset($input['transId'])) {
            $dealRecord = $this->checkDuplicateTransaction($input['transId'], $input['betId'] ?? '', $functionName);
            if ($dealRecord) {
                return $dealRecord; // 返回重複交易的錯誤 response
            }
        }

        return null; // 驗證通過
    }

    /**
     * 檢查重複交易
     *
     * @param  string  $transId  交易號
     * @param  string  $betId  注單號
     * @param  string  $actionType  操作類型（用於日誌）
     * @return array|null 如果是重複交易，返回錯誤 response 陣列；否則返回 null
     */
    private function checkDuplicateTransaction(string $transId, string $betId, string $actionType): ?array
    {
        // 使用索引友好的查询：先用 reference_no 过滤，再检查 status
        // status > 0 比 status != 0 更精确，且可以使用索引
        $dealRecord = $this->dealRecordClass::query()
            ->where('reference_no', $transId)
            ->where('status', '>', 0)
            ->where('action', $actionType) // 添加 action 过滤，提高精确度
            ->select(['id', 'reference_no', 'status'])
            ->first();

        if (!$dealRecord) {
            return null; // 不是重複交易
        }

        // 記錄重複交易日誌
        Log::info(sprintf('[%s] duplicate transaction %s', $this->vendorCode, $actionType), [
            'deal_record_id' => $dealRecord->id,
            'bet_id' => $betId,
            'reference_no' => $transId,
            'username' => $this->account->username ?? 'unknown',
        ]);

        // 根據先前的執行狀態返回不同的錯誤碼
        $errorCode = ($dealRecord->status == 1)
            ? -6 // 重複的訂單，先前提交成功
            : -7;  // 重複的訂單，先前提交失敗

        return [
            'code' => $errorCode,
            'description' => 'Duplicate transId',
        ];
    }

    /**
     * ============================ API 方法 ============================
     */

    /**
     * 取得餘額
     */
    public function balance(Request $request)
    {
        $vendorBalance = $this->getVendorBalance();

        return response()->json([
            'code' => 0,
            'description' => 'Success',
            'balance' => $vendorBalance,
        ]);
    }

    /**
     * 投注
     */
    public function bet(Request $request)
    {
        $input = $request->all();

        // 統一驗證：參數、securityToken、重複交易檢查
        $validationError = $this->validateRequest($input, [
            'username' => 'required|string',
            'betAmount' => 'required|numeric|min:0',  // 添加最小值验证
            'realBetAmount' => 'required|numeric|min:0',  // 添加最小值验证
            'transId' => 'required|string|max:100',
            'betId' => 'required|string|max:50',
            'drawType' => 'required|string',
            'drawName' => 'required|string',
            'reqDateTime' => 'required|string',
            'opCode' => 'required|string',
            'securityToken' => 'required|string',
        ], 'PlayerBet');

        if ($validationError) {
            return response()->json($validationError);
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) 'lobby');
        if (!$ableToBet) {
            return response()->json([
                'code' => -1,
                'description' => 'Account could not bet yet',
            ]);
        }

        // 額外的金額驗證：防止異常大額或零投注
        $betAmount = abs((float) $input['betAmount']);
        $realBetAmount = abs((float) $input['realBetAmount']);

        if ($betAmount <= 0 || $realBetAmount <= 0) {
            Log::warning(sprintf('[%s] invalid bet amount: betAmount=%s, realBetAmount=%s',
                $this->vendorCode, $betAmount, $realBetAmount));

            return response()->json([
                'code' => -1,
                'description' => 'Invalid bet amount',
            ]);
        }

        // 防止異常大額投注（可根据业务调整阈值）
        if ($betAmount > 1000000 || $realBetAmount > 1000000) {
            Log::warning(sprintf('[%s] suspicious large bet: betAmount=%s, realBetAmount=%s',
                $this->vendorCode, $betAmount, $realBetAmount));
            BotNotify::send(sprintf('[%s] 異常大額投注 - 用戶: %s, 金額: %s',
                $this->traceId, $input['username'], $betAmount));
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 投注金額轉換匯率回我方幣值
            $betAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
            // 真實投注金額轉換匯率回我方幣值
            $realBetAmount = Common::numDiv($realBetAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 預期餘額 = 目前餘額 - 投注金額
            $expectedRemainBalance = Common::numSub($balance, $betAmount, 4);

            // 餘額不足時回滾事務
            if (Common::numCompare($expectedRemainBalance, '0') < 0) {
                DB::rollBack();

                Log::error(sprintf('[%s] insufficient balance: %s', $this->vendorCode, $input['username']));

                return response()->json([
                    'code' => -4,
                    'description' => 'Insufficient balance',
                ]);
            }

            // 創建投注記錄
            $betRecord = $this->betRecordClass::create([
                'vendor_info_id' => $this->vendorInfo->id,
                'member_id' => $this->member->id,
                'username' => $this->account->username,
                'bet_id' => $input['betId'],
                'reference_no' => $input['transId'],
                'game_code' => 'lobby', // ! NEX4D 沒有遊戲代碼，暫時用 lobby 代替
                'bet' => $betAmount,
                'win' => 0,
                'valid_bet' => $realBetAmount,
                'win_lose' => Common::numMul($betAmount, -1, 4), // 初始為輸錢狀態，將投注金額轉負值
                'rolling_chip' => 0,
                'bet_time' => $this->convertToUtc($input['reqDateTime']),
                'payout_time' => null,
                'status' => BetRecordStatusEnum::BET,
            ]);

            // 調整會員餘額
            $this->changeBalance($this->member, $walletDto, null, $betRecord);

            DB::commit();

            Log::info(sprintf('[%s] bet success: %s', $this->vendorCode, $input['betId']), [
                'bet_record_id' => $betRecord->id,
                'username' => $this->account->username,
                'bet_amount' => $betAmount,
                'real_bet_amount' => $realBetAmount,
                'balance_before' => $balance,
                'balance_after' => $expectedRemainBalance,
            ]);

            // 計算最終餘額（扣款前餘額 - 投注金額）
            $finalBalance = Common::numSub($balance, $betAmount, 4);
            $finalVendorBalance = (float) Common::numMul($finalBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'code' => 0,
                'description' => 'Success',
                'balance' => $finalVendorBalance,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] bet failed: %s', $this->vendorCode, $input['betId']), [
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'code' => -1,
                'description' => 'Fail to bet',
            ]);
        }
    }

    /**
     * 結算 (派彩)
     */
    public function payout(Request $request)
    {
        $input = $request->all();

        // 統一驗證：參數、securityToken、重複交易檢查
        $validationError = $this->validateRequest($input, [
            'username' => 'required|string',
            'payoutAmount' => 'required|numeric|min:0',  // 添加最小值验证
            'betAmount' => 'required|numeric|min:0',
            'realBetAmount' => 'required|numeric|min:0',
            'transId' => 'required|string|max:100',
            'betId' => 'required|string|max:50',
            'drawType' => 'required|string',
            'drawName' => 'required|string',
            'isResettle' => 'required|string',
            'reqDateTime' => 'required|string',
            'opCode' => 'required|string',
            'securityToken' => 'required|string',
        ], 'PlayerPayout');

        if ($validationError) {
            return response()->json($validationError);
        }

        DB::beginTransaction();
        try {
            // 查詢投注紀錄（先查询，避免不必要的余额锁定）
            $oldBetRecord = $this->betRecordClass::query()
                ->where('bet_Id', $input['betId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                Log::warning(sprintf('[%s] payout failed - bet record not found: %s', $this->vendorCode, $input['betId']));

                DB::rollBack();

                return response()->json([
                    'code' => -3,
                    'description' => 'Bet record not found',
                ]);
            }

            // 幂等性保护：如果已經結算，直接返回成功
            if ($oldBetRecord->status == BetRecordStatusEnum::PAYOUT) {
                DB::rollBack();

                // 获取当前余额返回
                $currentVendorBalance = $this->getVendorBalance();

                return response()->json([
                    'code' => 0,
                    'description' => 'Success',
                    'balance' => $currentVendorBalance,
                ]);
            }

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $newBetRecord = clone $oldBetRecord;

            $payoutAmount = abs((float) $input['payoutAmount']);
            $payoutAmount = Common::numDiv($payoutAmount, $this->vendorInfo->rate, 4);
            // 投注金額轉換匯率回我方幣值
            $betAmount = abs((float) $input['betAmount']);
            $betAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
            // 真實投注金額轉換匯率回我方幣值
            $realBetAmount = abs((float) $input['realBetAmount']);
            $realBetAmount = Common::numDiv($realBetAmount, $this->vendorInfo->rate, 4);

            // 投注額異動
            if (Common::numCompare($betAmount, $oldBetRecord->bet) != 0 ||
                Common::numCompare($realBetAmount, $oldBetRecord->valid_bet) != 0) {
                Log::emergency(sprintf('[%s] payout failed - bet amount changed: %s', $this->vendorCode, $input['betId']), [
                    'bet_record_id' => $oldBetRecord->id,
                    'username' => $this->account->username,
                    'old_bet' => $oldBetRecord->bet,
                    'new_bet' => $betAmount,
                    'old_valid_bet' => $oldBetRecord->valid_bet,
                    'new_valid_bet' => $realBetAmount,
                ]);

                BotNotify::send(sprintf('[%s] NEX4DSW 投注金額異動 - 交易碼: %s, 注單號: %s', $this->traceId, $input['transId'], $input['betId']));
            }

            // 更新投注紀錄
            $newBetRecord->bet = $betAmount;
            $newBetRecord->valid_bet = $realBetAmount;
            $newBetRecord->win = $payoutAmount;
            $newBetRecord->win_lose = Common::numAdd($oldBetRecord->win_lose, $payoutAmount, 4);
            $newBetRecord->status = BetRecordStatusEnum::PAYOUT;
            $newBetRecord->payout_time = $this->convertToUtc($input['reqDateTime']);
            $newBetRecord->reference_no = $input['transId'];

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $newBetRecord);

            // ! 建立 TotalBetRecord
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($newBetRecord, $transactionLogId);

            DB::commit();

            // 获取更新后的最新余额
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'code' => 0,
                'description' => 'Success',
                'balance' => $currentVendorBalance,
            ]);

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error($this->vendorCode . ' payout exception', [
                'transId' => $input['transId'],
                'betId' => $input['betId'],
                'username' => $input['username'],
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * 取消投注 (未派彩)
     */
    public function cancel(Request $request)
    {
        $input = $request->all();

        // 統一驗證：參數、securityToken、重複交易檢查
        $validationError = $this->validateRequest($input, [
            'username' => 'required|string',
            'betAmount' => 'required|numeric',
            'realBetAmount' => 'required|numeric',
            'transId' => 'required|string|max:100',
            'betId' => 'required|string|max:50',
            'drawType' => 'required|string',
            'drawName' => 'required|string',
            'reqDateTime' => 'required|string',
            'opCode' => 'required|string',
            'securityToken' => 'required|string',
        ], 'PlayerCancelBet');

        if ($validationError) {
            return response()->json($validationError);
        }

        DB::beginTransaction();
        try {
            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 查詢投注紀錄
            $oldBetRecord = $this->betRecordClass::query()
                ->where('bet_Id', $input['betId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                Log::warning(sprintf('[%s] cancel failed - bet record not found: %s', $this->vendorCode, $input['betId']));

                DB::rollBack();

                return response()->json([
                    'code' => -3,
                    'description' => 'Bet record not found',
                ]);
            }

            // 已經結算，不能取消
            if ($oldBetRecord->status == BetRecordStatusEnum::PAYOUT) {
                Log::info(sprintf('[%s] cancel failed - bet already settled: %s', $this->vendorCode, $input['betId']), [
                    'bet_record_id' => $oldBetRecord->id,
                    'username' => $this->account->username,
                ]);
                DB::rollBack();

                return response()->json([
                    'code' => -6,
                    'description' => 'Bet already settled',
                ]);
            }

            // 已經取消，防止重複操作
            if ($oldBetRecord->status == BetRecordStatusEnum::CANCEL) {
                Log::info(sprintf('[%s] cancel failed - bet already cancelled: %s', $this->vendorCode, $input['betId']), [
                    'bet_record_id' => $oldBetRecord->id,
                    'username' => $this->account->username,
                ]);
                DB::rollBack();

                return response()->json([
                    'code' => -6,
                    'description' => 'Bet already cancelled',
                ]);
            }

            $newBetRecord = clone $oldBetRecord;

            // 更新投注紀錄
            $newBetRecord->bet = 0;
            $newBetRecord->valid_bet = 0;
            $newBetRecord->win = 0;
            $newBetRecord->win_lose = 0;
            $newBetRecord->status = BetRecordStatusEnum::CANCEL;
            $newBetRecord->reference_no = $input['transId'];

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $newBetRecord);

            // ! 更新 TotalBetRecord
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($newBetRecord, $transactionLogId);

            DB::commit();

            // 获取更新后的最新余额
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'code' => 0,
                'description' => 'Success',
                'balance' => $currentVendorBalance,
            ]);

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error($this->vendorCode . ' cancel exception', [
                'transId' => $input['transId'],
                'betId' => $input['betId'],
                'username' => $input['username'],
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * 回收獎金 (注單有效但彩金改變)
     */
    public function revertPayout(Request $request)
    {
        $input = $request->all();

        // 統一驗證：參數、securityToken、重複交易檢查
        $validationError = $this->validateRequest($input, [
            'username' => 'required|string',
            'payoutAmount' => 'required|numeric',
            'transId' => 'required|string|max:100',
            'betId' => 'required|string|max:50',
            'drawType' => 'required|string',
            'drawName' => 'required|string',
            'reqDateTime' => 'required|string',
            'opCode' => 'required|string',
            'securityToken' => 'required|string',
        ], 'PlayerRevertPayout');

        if ($validationError) {
            return response()->json($validationError);
        }

        DB::beginTransaction();
        try {
            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $vendorBalance = Common::numMul($balance, $this->vendorInfo->rate, 4);

            // 取消金額轉換匯率回我方幣值
            $payoutAmount = abs((float) $input['payoutAmount']);
            $payoutAmount = Common::numDiv($payoutAmount, $this->vendorInfo->rate, 4);

            // 查詢投注紀錄
            $oldBetRecord = $this->betRecordClass::query()
                ->where('bet_Id', $input['betId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                Log::warning(sprintf('[%s] revertPayout failed - bet record not found: %s', $this->vendorCode, $input['betId']));

                DB::rollBack();

                return response()->json([
                    'code' => -3,
                    'description' => 'Bet record not found',
                ]);
            }

            // 必須是已派彩狀態才能回收獎金
            if ($oldBetRecord->status != BetRecordStatusEnum::PAYOUT) {
                Log::info(sprintf('[%s] revertPayout failed - bet not settled yet: %s', $this->vendorCode, $input['betId']), [
                    'bet_record_id' => $oldBetRecord->id,
                    'username' => $this->account->username,
                    'current_status' => $oldBetRecord->status,
                ]);
                DB::rollBack();

                return response()->json([
                    'code' => -1,
                    'description' => 'Bet not settled yet, cannot revert payout',
                ]);
            }

            $newBetRecord = clone $oldBetRecord;

            // 更新投注紀錄 - 扣除彩金
            $newBetRecord->win = Common::numSub($oldBetRecord->win, $payoutAmount, 4);
            $newBetRecord->win_lose = Common::numSub($oldBetRecord->win_lose, $payoutAmount, 4);
            $newBetRecord->status = BetRecordStatusEnum::REFUND;
            $newBetRecord->reference_no = $input['transId'];

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $newBetRecord);

            // ! 更新 TotalBetRecord
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($newBetRecord, $transactionLogId);

            DB::commit();

            // 获取更新后的最新余额
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'code' => 0,
                'description' => 'Success',
                'balance' => $currentVendorBalance,
            ]);

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error($this->vendorCode . ' revertPayout exception', [
                'transId' => $input['transId'],
                'betId' => $input['betId'],
                'username' => $input['username'],
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }
}
