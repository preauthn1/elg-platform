<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\General\ContextKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\PpswAccount;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\Models\PpswDealRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PpswController extends BaseController
{
    // 錯誤碼常量
    protected const int SUCCESS = 0;

    protected const int INSUFFICIENT_BALANCE = 1; // 余额不足

    protected const int PLAYER_NOT_FOUND = 2; // 未找到玩家或已注销

    protected const int INVALID_BET = 3; // 玩家不允许投注

    protected const int TOKEN_UNAUTHORIZED = 4; // 令牌无效、未找到或过期，玩家身份验证失败

    protected const int HASH_ERROR = 5; // 哈希码验证失败

    protected const int PLAYER_BLOCKED = 6; // 玩家被冻结

    protected const int INVALID_PARAMS = 7; // 请求参数错误

    protected const int GAME_NOT_FOUND = 8; // 游戏未找到或已停用

    protected const int INTERNAL_ERROR_RETRY = 100; // 内部服务器错误 需要重试请求

    protected const int INTERNAL_ERROR = 120; // 内部服务器错误

    protected string $vendorCode = 'PPSW';

    protected int $vendorId = VendorIdEnum::PPSW->value;

    protected string $accountClass = PpswAccount::class;

    protected string $betRecordClass = PpswBetRecord::class;

    protected string $actionPrefix = 'PPSW_get_request_';

    protected array $vendorConfig; // 商戶配置

    protected string $traceId; // 錯誤的追蹤碼

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 作為 transactionId 必須將長度控制在 32 字元以內
            $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
            $this->traceId = str_replace('-', '', $traceId);

            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new ApiException('IP not in whitelist');
            }

            $params = $request->all();

            // 驗證必要的請求參數
            $validator = Validator::make($params, [
                'hash' => 'required|string',
                'providerId' => 'required|string',
                'token' => 'nullable|string|required_without:userId',
                'userId' => 'nullable|string|required_without:token',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => self::INVALID_PARAMS,
                    'description' => $validator->errors()->first(),
                ]);
            }

            // 設定用戶與遊戲商戶
            if ($method === 'authenticate') {
                // 透過 token 取得玩家帳號與商戶配置
                if (!$params['token']) {
                    return response()->json([
                        'error' => self::INVALID_PARAMS,
                        'description' => 'Missing necessary fields',
                    ]);
                }

                $this->getPlayerByToken($params['token']);
            } else {
                // 透過 username 取得玩家帳號與商戶配置
                if (!$params['userId']) {
                    return response()->json([
                        'error' => self::INVALID_PARAMS,
                        'description' => 'Missing necessary fields',
                    ]);
                }

                $this->getPlayerByUsername($params['userId']);
            }

            // 檢查 hash
            $this->verifyHash($params, $this->vendorConfig['SECRET_KEY']);

            // 檢查 providerId
            $this->verifyProviderId($params, $this->vendorConfig['PROVIDER_ID']);

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response
            return $response;

        } catch (HttpResponseException $e) {
            // 已經是回應錯誤，直接拋出
            throw $e;
        } catch (Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';

            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * ============================ 獲取玩家帳號 ============================
     */

    /**
     * 載入玩家帳號（通用方法）
     *
     * @param  string  $field  查詢欄位名稱（token 或 username）
     * @param  string  $value  查詢值
     *
     * @throws HttpResponseException
     */
    private function loadPlayer(string $field, string $value): void
    {
        // 先載入相關關聯，方便檢查各項狀態
        /** @var PpswAccount $account */
        $account = PpswAccount::with(['vendorInfo', 'member'])
            ->where($field, $value)
            ->first();

        if (!$account) {
            Log::warning(sprintf('[%s] 查詢玩家帳號失敗 - %s', $this->vendorCode, $field), [$field => $value]);
            $this->throwPlayerNotFoundError($field);
        }

        // 驗證帳號狀態
        $this->validateAccountStatus($account, $field, $value);

        // 設定屬性
        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $account->vendorInfo;
        $this->vendorConfig = $account->vendorInfo->info;
    }

    /**
     * 拋出玩家未找到錯誤
     *
     * @throws HttpResponseException
     */
    private function throwPlayerNotFoundError(string $field): void
    {
        if ($field === 'token') {
            throw new HttpResponseException(response()->json([
                'error' => self::TOKEN_UNAUTHORIZED,
                'message' => 'Invalid token.',
            ]));
        }

        if ($field === 'active') {
            throw new HttpResponseException(response()->json([
                'error' => self::PLAYER_BLOCKED,
                'message' => 'Player is blocked or inactive',
            ]));
        }

        throw new HttpResponseException(response()->json([
            'error' => self::PLAYER_NOT_FOUND,
            'message' => 'Player not found',
        ]));
    }

    /**
     * 驗證帳號狀態
     */
    private function validateAccountStatus(PpswAccount $account, string $field, string $value): void
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
            Log::warning(sprintf('[%s] 玩家帳號狀態受限 %s', $this->vendorCode, $account->username), [
                $field => $value,
                'reasons' => $reasons,
            ]);

            $this->throwPlayerNotFoundError('active');
        }
    }

    /**
     * 透過 token 取得玩家
     *
     * @throws HttpResponseException
     */
    private function getPlayerByToken(string $token): void
    {
        $this->loadPlayer('token', $token);
    }

    /**
     * 透過 username 取得玩家
     *
     * @throws HttpResponseException
     */
    private function getPlayerByUsername(string $username): void
    {
        $this->loadPlayer('username', $username);
    }

    /**
     * ============================ 驗證請求 ============================
     */

    /**
     * 計算 Hash（依題意：排序參數、拼接 key=value&...，接上 secret，MD5）
     *
     * @param  array  $params  所有 POST 參數（會忽略 'hash' 鍵）
     * @param  string  $secretKey  密鑰（直接接在參數字串後面）
     * @return string 小寫 32 字元 md5 字串
     */
    private function calculateHash(array $params, string $secretKey): string
    {
        // 複製並移除 hash 欄位（若存在）
        if (array_key_exists('hash', $params)) {
            unset($params['hash']);
        }

        // 按鍵名字母順序排序
        ksort($params);

        // 組成 key=value&key2=value2 的字串（不做額外編碼）
        $baseString = http_build_query($params);

        // 接上密鑰並 MD5
        $toHash = $baseString . $secretKey;

        return md5($toHash);
    }

    /**
     * 驗證並比對傳入的 hash（使用 timing-safe 比較）
     *
     * @param  array  $params  所有 POST 參數（包含 'hash'）
     * @param  string  $secretKey  密鑰
     */
    private function verifyHash(array $params, string $secretKey): void
    {
        if (!array_key_exists('hash', $params) || !is_string($params['hash'])) {
            throw new HttpResponseException(response()->json([
                'error' => self::HASH_ERROR,
                'message' => 'Unauthorized - Incorrect secure login or calculated hash.',
            ]));
        }

        $receivedHash = $params['hash'];
        $calculatedHash = $this->calculateHash($params, $secretKey);

        // 使用 hash_equals 做 timing-safe 比較
        if (hash_equals($calculatedHash, $receivedHash) === false) {
            throw new HttpResponseException(response()->json([
                'error' => self::HASH_ERROR,
                'message' => 'Unauthorized - Incorrect secure login or calculated hash.',
            ]));
        }
    }

    /**
     * 驗證並比對 providerId
     *
     * @param  array  $params  所有 POST 參數（包含 'hash'）
     * @param  string  $providerId  遊戲商代號
     */
    private function verifyProviderId(array $params, string $providerId): void
    {
        if (isset($params['providerId']) && $params['providerId'] !== $providerId) {
            Log::error(sprintf('[%s] 無效的 providerId %s', $this->vendorCode, $params['providerId']), [
                'expected_providerId' => $providerId,
                'received_providerId' => $params['providerId'],
            ]);

            throw new HttpResponseException(response()->json([
                'error' => self::INVALID_PARAMS,
                'message' => 'Invalid providerId.',
            ]));
        }
    }

    /**
     * 檢查遊戲是否存在
     */
    private function validateGameExists(?string $gameCode): void
    {
        if ($gameCode) {
            $game = $this->getGameByCode($gameCode);
            if (!$game) {
                Log::error(sprintf('[%s] 不支援的遊戲代碼 %s', $this->vendorCode, $gameCode), [
                    'game_code' => $gameCode,
                ]);

                throw new HttpResponseException(response()->json([
                    'error' => self::GAME_NOT_FOUND,
                    'description' => 'This game is not supported',
                ]));
            }
        }
    }

    /**
     * ============================ 工具方法 ============================
     */

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
     * ============================ 交易紀錄方法 ============================
     */

    /**
     * 檢查交易紀錄是否已完成 (確保請求幕等性)
     */
    private function isDealRecordFinished(string $action, array $params): ?PpswDealRecord
    {
        return PpswDealRecord::query()
            ->where('username', $this->account->username)
            ->where('reference_no', $params['reference'])
            ->where('action', $action)
            ->where('status', 1)
            ->first();
    }

    /**
     * 建立交易紀錄
     */
    private function createDealRecord(string $action, array $params): PpswDealRecord
    {
        return PpswDealRecord::create([
            'trace_id' => $this->traceId,
            'username' => $this->account->username,
            'bet_id' => $params['roundId'] ?? $params['campaignId'] ?? null,  // promoWin 使用 campaignId
            'reference_no' => $params['reference'],
            'action' => $action,
            'request' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 0,
        ]);
    }

    /**
     * 完成交易紀錄
     */
    private function finishDealRecord(PpswDealRecord $dealRecord, ?int $transactionLogId = null): void
    {
        $dealRecord->update([
            'status' => 1,
            'transaction_log_id' => $transactionLogId,
        ]);

        $dealRecord->refresh();
    }

    /**
     * @throws Exception
     */
    private function createTotalBetRecord($betRecord, $transactionLogId, $walletDto): void
    {
        $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        $totalBetRecordService->create($betRecord, $transactionLogId);
    }

    /**
     * ============================ API 方法 ============================
     */

    /**
     * 用戶認證
     */
    public function authenticate()
    {
        $vendorBalance = $this->getVendorBalance();

        return response()->json([
            'error' => self::SUCCESS,
            'description' => 'Success',
            'userId' => $this->account->username,
            'currency' => $this->account->currency,
            'cash' => $vendorBalance,
            'bonus' => 0,
        ]);
    }

    /**
     * 取得餘額
     */
    public function balance()
    {
        $vendorBalance = $this->getVendorBalance();

        return response()->json([
            'error' => self::SUCCESS,
            'description' => 'Success',
            'currency' => $this->account->currency,
            'cash' => $vendorBalance,
            'bonus' => 0,
        ]);
    }

    /**
     * 投注
     */
    public function bet(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 參數驗證
        $validator = Validator::make($params, [
            'gameId' => 'required|string',
            'roundId' => 'required|string',
            'amount' => 'required|string',
            'reference' => 'required|string',
            'timestamp' => 'required|integer',
            'roundDetails' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        $this->validateGameExists($params['gameId']);

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gameId']);
        if (!$ableToBet) {
            return response()->json([
                'error' => self::INVALID_BET,
                'description' => 'Account could not bet yet',
            ]);
        }

        // 幂等性檢查必須在事務外執行，避免併發問題
        $dealRecord = $this->isDealRecordFinished($action, $params);

        if ($dealRecord) {
            Log::warning(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'deal_record_id' => $dealRecord->id,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
                'usedPromo' => 0,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 轉換匯率回我方幣值
            $vendorAmount = abs((float) $params['amount']);
            $amount = Common::numDiv($vendorAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $vendorBalance = Common::numMul($balance, $this->vendorInfo->rate, 4);

            // 創建交易紀錄（狀態為未完成）
            $dealRecord = $this->createDealRecord($action, $params);

            // 預期餘額 = 目前餘額 - 投注金額
            $expectedRemainBalance = Common::numSub($balance, $amount, 4);

            // 餘額不足時回滾事務
            if (Common::numCompare($expectedRemainBalance, '0') < 0) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗，餘額不足 %s', $this->vendorCode, $action, $params['reference']), [
                    'reference' => $params['reference'],
                    'username' => $this->account->username,
                    'current_balance' => $balance,
                    'bet_amount' => $amount,
                    'game_code' => $params['gameId'],
                    'round_id' => $params['roundId'],
                ]);

                return response()->json([
                    'error' => self::INSUFFICIENT_BALANCE,
                    'description' => 'Insufficient balance',
                ]);
            }

            // 將 timestamp 從毫秒轉換爲秒並轉成 Carbon
            $betTimestamp = ($params['timestamp'] > 10000000000)
                ? (int) floor($params['timestamp'] / 1000)
                : (int) $params['timestamp'];
            $betTime = Carbon::createFromTimestamp($betTimestamp);

            // 同 RoundID 可能會有多次 bet 請求（multi-bet round），需累加到同一筆 PpswBetRecord
            // 冪等性已由 isDealRecordFinished + ppsw_deal_records 唯一鍵保證，這裡僅處理業務累加
            // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
            // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
            // bet_id 非 UNIQUE，普通索引的鎖定讀一樣取 gap lock。同 round 的累加靠上方
            // getMemberLockedWallets() 的會員列鎖序列化（roundId 屬單一玩家），冪等性靠 ppsw_deal_records 唯一鍵。
            /** @var PpswBetRecord|null $existingBetRecord */
            $existingBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('username', $this->account->username)
                ->first();

            if ($existingBetRecord) {
                // 同 round 已有 bet_record：累加 bet/valid_bet/win_lose（reference_no/bet_time 保留首筆）
                $oldBetRecord = clone $existingBetRecord;
                $existingBetRecord->update([
                    'bet' => Common::numAdd($existingBetRecord->bet, $amount, 4),
                    'valid_bet' => Common::numAdd($existingBetRecord->valid_bet, $amount, 4),
                    'win_lose' => Common::numSub($existingBetRecord->win_lose, $amount, 4),
                ]);
                $existingBetRecord->refresh();
                $betRecord = $existingBetRecord;

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            } else {
                // 新 round：建立 bet_record
                $betRecord = PpswBetRecord::create([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $params['roundId'],
                    'reference_no' => $params['reference'],
                    'game_code' => $params['gameId'],
                    'bet' => $amount,
                    'valid_bet' => $amount,
                    'win_lose' => Common::numMul($amount, -1, 4),
                    'rolling_chip' => 0,
                    'bet_time' => $betTime,
                    'payout_time' => null,
                    'status' => BetRecordStatusEnum::BET,
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);
            }

            // 標記交易記錄為完成
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // !!! 需求變更：收到 endRound 後才建立 TotalBetRecord
            // $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            Log::info(sprintf('[%s] %s 成功 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $betRecord->id,
                'bet_amount' => $amount,
                'transaction_log_id' => $transactionLogId,
            ]);

            // 計算最終餘額（扣款前餘額 - 投注金額）
            $finalBalance = Common::numSub($balance, $amount, 4);
            $finalVendorBalance = Common::numMul($finalBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => (float) $finalVendorBalance,
                'bonus' => 0,
                'usedPromo' => 0,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗：%s', $this->vendorCode, $action, Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 結束回合通知
     */
    public function endRound(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 驗證參數
        $validator = Validator::make($params, [
            'gameId' => 'required|string',
            'roundId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        $this->validateGameExists($params['gameId']);

        // 開始事務
        DB::beginTransaction();
        try {
            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $vendorBalance = Common::numMul($balance, $this->vendorInfo->rate, 4);

            // 查詢投注紀錄
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('game_code', $params['gameId'])
                ->where('username', $params['userId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗 %s：投注紀錄不存在', $this->vendorCode, $action, $params['roundId']), [
                    'roundId' => $params['roundId'],
                    'gameId' => $params['gameId'],
                    'userId' => $params['userId'],
                    'username' => $this->account->username,
                ]);

                return response()->json([
                    'error' => self::INTERNAL_ERROR,
                    'description' => 'Bet record not found',
                ]);
            }

            // 幂等性檢查：如果已經是 END_ROUND 或更後面的狀態，直接返回成功
            if (in_array($oldBetRecord->status, [BetRecordStatusEnum::END_ROUND, BetRecordStatusEnum::SETTLE, BetRecordStatusEnum::REFUND])) {
                DB::rollBack();

                Log::info(sprintf('[%s] %s 重複請求 %s，當前狀態：%s', $this->vendorCode, $action, $params['roundId'], $oldBetRecord->status), [
                    'roundId' => $params['roundId'],
                    'username' => $this->account->username,
                    'current_status' => $oldBetRecord->status,
                    'bet_record_id' => $oldBetRecord->id,
                ]);

                return response()->json([
                    'error' => self::SUCCESS,
                    'description' => 'Success',
                    'cash' => (float) $vendorBalance,
                    'bonus' => 0,
                ]);
            }

            $betRecord = clone $oldBetRecord;

            // ! 更新 payout_time, 不要更新 status 會造成派彩失敗
            $betRecord->update([
                'payout_time' => now()->toDateTimeString(),
                'status' => BetRecordStatusEnum::END_ROUND,
            ]);

            // 刷新模型以确保与数据库同步（特别是 updated_at 等自动更新的字段）
            $betRecord->refresh();

            // ! 多餘的操作只為了取得 transactionLogId
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            // ! 建立 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            Log::info(sprintf('[%s] %s 成功 %s', $this->vendorCode, $action, $params['roundId']), [
                'roundId' => $params['roundId'],
                'username' => $this->account->username,
            ]);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'cash' => (float) $vendorBalance,
                'bonus' => 0,
            ]);

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗 %s：%s', $this->vendorCode, $action, $params['roundId'], Str::limit($e->getMessage(), 97)), [
                'roundId' => $params['roundId'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 退款
     */
    public function refund(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 參數驗證
        $validator = Validator::make($params, [
            'reference' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // 幂等性檢查（事務外）
        $dealRecord = $this->isDealRecordFinished($action, $params);

        if ($dealRecord) {
            Log::warning(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
            ]);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 創建交易紀錄（狀態為未完成）
            $dealRecord = $this->createDealRecord($action, $params);

            $referenceDealRecord = PpswDealRecord::query()
                ->where('username', $this->account->username)
                ->where('reference_no', $params['reference'])
                ->lockForUpdate()
                ->first();

            if (!$referenceDealRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 找不到原始交易紀錄 %s，視為已完成', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                ]);

                // 文件規格：找不到 Bet 交易時不應執行任何操作，回傳成功
                return response()->json([
                    'error' => self::SUCCESS,
                    'description' => 'Success',
                    'transactionId' => $dealRecord->trace_id,
                ]);
            }

            // 透過 bet_id 確認投注紀錄存在（使用 lockForUpdate 確保不會併發處理）
            /** @var PpswBetRecord $oldBetRecord */
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $referenceDealRecord->bet_id)
                ->where('username', $params['userId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 找不到投注紀錄 %s，視為已完成', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                    'bet_id' => $referenceDealRecord->bet_id,
                ]);

                // 文件規格：找不到 Bet 交易時不應執行任何操作，回傳成功
                return response()->json([
                    'error' => self::SUCCESS,
                    'description' => 'Success',
                    'transactionId' => $dealRecord->trace_id,
                ]);
            }

            // 已退款，直接返回成功（保持幂等性不重複退款）
            if ($oldBetRecord->status === BetRecordStatusEnum::REFUND) {
                DB::rollBack();

                Log::warning(sprintf('[%s] 重複的 %s 請求 %s，注單狀態已經是已退款', $this->vendorCode, $action, $params['reference']), [
                    'reference' => $params['reference'],
                    'username' => $this->account->username,
                    'bet_record_id' => $oldBetRecord->id,
                ]);

                return response()->json([
                    'error' => self::SUCCESS,
                    'description' => 'Success',
                    'transactionId' => $dealRecord->trace_id,
                ]);
            }

            // 檢查狀態是否允許退款（只有 IN_PROGRESS 和 SETTLE 可以退款）
            // if (!in_array($oldBetRecord->status, [BetRecordStatusEnum::END_ROUND, BetRecordStatusEnum::SETTLE])) {
            //     DB::rollBack();
            //
            //     Log::error($this->vendorCode . ' refund failed - invalid status', [
            //         'reference' => $params['reference'],
            //         'username' => $this->account->username,
            //         'current_status' => $oldBetRecord->status,
            //         'bet_record_id' => $oldBetRecord->id,
            //     ]);
            //
            //     return response()->json([
            //         'error' => self::CODE_INTERNAL_ERROR,
            //         'description' => 'The transaction cannot be refunded due to its current status',
            //         // 'transactionId' => $dealRecord->trace_id,
            //     ]);
            // }

            // 進行退款
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = now();

            // ! 執行退款但不更新遊戲商投注紀錄
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord, false);

            // 標記交易記錄為完成
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // ! 使用金額為 0 的投注紀錄更新 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            // ! 更新投注紀錄狀態，但保持金額欄位不變
            $betRecord->update([
                'status' => BetRecordStatusEnum::REFUND,
            ]);

            $betRecord->refresh();

            DB::commit();

            Log::info(sprintf('[%s] %s 成功 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $oldBetRecord->id,
                'refund_amount' => $oldBetRecord->bet,
            ]);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗 %s：%s', $this->vendorCode, $action, $params['reference'], Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 中獎派彩
     *
     * @throws Throwable
     */
    public function result(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 參數驗證
        $paramsRules = [
            'amount' => 'required|string',
            'reference' => 'required|string',
            'timestamp' => 'required|integer',
            'roundId' => 'required|string',
            'gameId' => 'required|string',
            'roundDetails' => 'required|string',
            'promoWinAmount' => 'nullable|string',
        ];

        // 合併基本驗證規則與額外規則
        $validator = Validator::make($params, $paramsRules);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 如果有 gameId，檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        if (isset($params['gameId'])) {
            $this->validateGameExists($params['gameId']);
        }

        // 幂等性檢查必須在事務外執行，避免併發問題
        $dealRecord = $this->isDealRecordFinished($action, $params);

        if ($dealRecord) {
            Log::warning(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'deal_record_id' => $dealRecord->id,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 轉換匯率回我方幣值
            $vendorAmount = abs((float) $params['amount']);
            $amount = Common::numDiv($vendorAmount, $this->vendorInfo->rate, 4);

            $promoWinAmount = abs((float) ($params['promoWinAmount'] ?? 0));
            $promoWinAmount = Common::numDiv($promoWinAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $vendorBalance = Common::numMul($balance, $this->vendorInfo->rate, 4);

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($action, $params);

            // 透過 roundId 確認投注紀錄存在（使用 lockForUpdate 確保不會併發處理）
            /** @var PpswBetRecord $oldBetRecord */
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('username', $params['userId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗 %s，投注紀錄不存在', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                ]);

                return response()->json([
                    'error' => self::INTERNAL_ERROR,
                    'description' => 'The bet record not found',
                ]);
            }

            // 重複派彩，直接返回成功（保持幂等性不重複結算）
            if ($oldBetRecord->status === BetRecordStatusEnum::SETTLE) {
                DB::rollBack();

                Log::warning(sprintf('[%s] 派彩失敗 %s，投注紀錄狀態為已派彩', $this->vendorCode, $params['reference']), [
                    'roundId' => $params['roundId'],
                    'reference' => $params['reference'],
                    'username' => $this->account->username,
                    'bet_record_id' => $oldBetRecord->id,
                ]);

                return response()->json([
                    'error' => self::SUCCESS,
                    'description' => 'Success',
                    'transactionId' => $dealRecord->trace_id,
                    'currency' => $this->account->currency,
                    'cash' => (float) $vendorBalance,
                    'bonus' => 0,
                ]);
            }

            // 將 timestamp 從毫秒轉換爲秒
            $payoutTimestamp = ($params['timestamp'] > 10000000000)
                ? (int) floor($params['timestamp'] / 1000)
                : (int) $params['timestamp'];

            $payoutTime = Carbon::createFromTimestamp($payoutTimestamp);

            // 更新投注紀錄，發放獎金到錢包
            $betRecord = clone $oldBetRecord;
            $betRecord->payout_time = $payoutTime;

            // 中獎
            $betRecord->status = BetRecordStatusEnum::SETTLE;
            $betRecord->win = Common::numAdd($oldBetRecord->win, $amount, 4);
            $betRecord->win_lose = Common::numAdd($oldBetRecord->win_lose, $amount, 4);
            // 派發額外獎金
            $betRecord->bonus = Common::numAdd($oldBetRecord->bonus, $promoWinAmount, 4);

            // 更新用戶餘額
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            // 標記交易記錄為完成
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // 更新 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            // 計算最終餘額（扣款前餘額 + 派彩金額）並轉回遊戲商幣值
            $finalBalance = Common::numAdd($balance, Common::numAdd($amount, $promoWinAmount, 4), 4);
            $finalVendorBalance = Common::numMul($finalBalance, $this->vendorInfo->rate, 4);

            Log::info(sprintf('[%s] 派彩成功 %s', $this->vendorCode, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $betRecord->id,
                'win' => $amount,
                'amount' => $finalVendorBalance,
            ]);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => (float) $finalVendorBalance,
                'bonus' => 0,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] 派彩失敗 %s，%s', $this->vendorCode, $params['reference'], Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 額外獎金
     * !!! 單除查詢餘額，不做任何交易，獎金會以 Result 接口派發
     *
     * @throws Throwable
     */
    public function bonusWin(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 參數驗證
        $paramsRules = [
            'amount' => 'required|string',
            'reference' => 'required|string',
            'timestamp' => 'required|integer',
            'roundId' => 'required|string', // ! 需要申請
            'gameId' => 'required|string', // ! 需要申請
            'bonusCode' => 'required|string',
        ];

        // 合併基本驗證規則與額外規則
        $validator = Validator::make($params, $paramsRules);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 如果有 gameId，檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        if (isset($params['gameId'])) {
            $this->validateGameExists($params['gameId']);
        }

        // 幂等性檢查必須在事務外執行，避免併發問題
        $dealRecord = $this->isDealRecordFinished($action, $params);

        if ($dealRecord) {
            Log::info(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'deal_record_id' => $dealRecord->id,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 轉換匯率回我方幣值
            $vendorAmount = abs((float) $params['amount']);
            $amount = Common::numDiv($vendorAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($action, $params);

            // 依 roundId 找到既有投注紀錄
            /** @var PpswBetRecord $oldBetRecord */
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('username', $this->account->username)
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗 %s，投注紀錄不存在', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                ]);

                return response()->json([
                    'error' => self::INTERNAL_ERROR,
                    'description' => 'The bet record not found',
                ]);
            }

            // 累加 bonus（避免多次請求時覆寫造成差額錯誤）
            $betRecord = clone $oldBetRecord;
            $betRecord->bonus = Common::numAdd($oldBetRecord->bonus, $amount, 4);
            $betRecord->payout_time = now();

            // 發放 Free Spins 總獎金到錢包
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            // 標記交易記錄為完成
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // 更新 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            Log::info(sprintf('[%s] %s 成功 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $betRecord->id,
                'amount' => $amount,
            ]);

            // 計算最終餘額（帶鎖餘額 + 入帳金額）
            $finalBalance = Common::numAdd($balance, $amount, 4);
            $finalVendorBalance = Common::numMul($finalBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => (float) $finalVendorBalance,
                'bonus' => 0,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗：%s', $this->vendorCode, $action, Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 彩池獎金
     * ! 需要以 jackpotId 作為 bet_id 建立一筆新的投注紀錄
     *
     * @throws Throwable
     */
    public function jackpotWin(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 參數驗證
        $paramsRules = [
            'amount' => 'required|string',
            'reference' => 'required|string',
            'timestamp' => 'required|integer',
            'roundId' => 'required|string', // ! 需要申請
            'gameId' => 'required|string',
            'jackpotId' => 'required|string',
        ];

        // 合併基本驗證規則與額外規則
        $validator = Validator::make($params, $paramsRules);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 如果有 gameId，檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        if (isset($params['gameId'])) {
            $this->validateGameExists($params['gameId']);
        }

        // 幂等性檢查必須在事務外執行，避免併發問題
        $dealRecord = $this->isDealRecordFinished($action, $params);

        if ($dealRecord) {
            Log::info(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'deal_record_id' => $dealRecord->id,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 轉換匯率回我方幣值
            $vendorAmount = abs((float) $params['amount']);
            $amount = Common::numDiv($vendorAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($action, $params);

            // 查詢投注紀錄
            /** @var PpswBetRecord $oldBetRecord */
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('username', $params['userId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗 %s，投注紀錄不存在', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                ]);

                return response()->json([
                    'error' => self::INTERNAL_ERROR,
                    'description' => 'The bet record not found',
                ]);
            }

            // 更新投注紀錄，發放獎金到錢包（累加避免多次請求時覆寫造成差額錯誤）
            $betRecord = clone $oldBetRecord;
            $betRecord->bonus = Common::numAdd($oldBetRecord->bonus, $amount, 4);
            $betRecord->payout_time = now();

            // 更新用戶餘額
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            // 標記交易記錄為完成
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // 更新 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            // 計算最終餘額（扣款前餘額 + 派彩金額）並轉回遊戲商幣值
            $finalBalance = Common::numAdd($balance, $amount, 4);
            $finalVendorBalance = Common::numMul($finalBalance, $this->vendorInfo->rate, 4);

            Log::info(sprintf('[%s] %s 成功', $this->vendorCode, $action), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $betRecord->id,
                'win' => $amount,
                'amount' => $finalVendorBalance,
            ]);

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => (float) $finalVendorBalance,
                'bonus' => 0,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗：%s', $this->vendorCode, $action, Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }

    /**
     * 錦標賽獎金（創建新投注記錄並發放獎金）
     * ! 需要以 campaignId 作為 bet_id 建立一筆新的投注紀錄
     *
     * @throws Throwable
     */
    public function promoWin(Request $request)
    {
        $action = __FUNCTION__;

        $params = $request->all();

        // 驗證參數
        $validator = Validator::make($params, [
            'timestamp' => 'required|integer',
            'campaignId' => 'required|string',
            'campaignType' => 'required|string',
            'roundId' => 'required|string', // ! 需要申請
            'gameId' => 'required|string', // ! 需要申請
            'amount' => 'required|string',
            'currency' => 'required|string',
            'reference' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => self::INVALID_PARAMS,
                'description' => $validator->errors()->first(),
            ]);
        }

        // ! 如果有 gameId，檢查遊戲是否存在，串接單錢包遊戲需確認遊戲存在才接受投注
        if (isset($params['gameId'])) {
            $this->validateGameExists($params['gameId']);
        }

        // 【幂等性檢查】先在事務外檢查交易紀錄是否已完成
        $finishedDeal = $this->isDealRecordFinished($action, $params);

        if ($finishedDeal) {
            Log::warning(sprintf('[%s] 重複的 %s 請求 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'trace_id' => $finishedDeal->trace_id,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $finishedDeal->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
            ]);
        }

        // 開始事務
        DB::beginTransaction();
        try {
            // 轉換匯率回我方幣值
            $vendorAmount = abs((float) $params['amount']);
            $amount = Common::numDiv($vendorAmount, $this->vendorInfo->rate, 4);

            // 取得當前餘額（帶鎖）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 建立交易紀錄
            $dealRecord = $this->createDealRecord($action, $params);

            // 查詢投注紀錄
            /** @var PpswBetRecord $oldBetRecord */
            $oldBetRecord = PpswBetRecord::query()
                ->where('bet_id', $params['roundId'])
                ->where('username', $params['userId'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                DB::rollBack();

                Log::warning(sprintf('[%s] %s 失敗 %s，投注紀錄不存在', $this->vendorCode, $action, $params['reference']), [
                    'reference_no' => $params['reference'],
                    'username' => $this->account->username,
                ]);

                return response()->json([
                    'error' => self::INTERNAL_ERROR,
                    'description' => 'The bet record not found',
                ]);
            }

            // 更新投注紀錄，發放獎金到錢包（累加避免多次請求時覆寫造成差額錯誤）
            $betRecord = clone $oldBetRecord;
            $betRecord->bonus = Common::numAdd($oldBetRecord->bonus, $amount, 4);
            $betRecord->payout_time = now();

            // 調整會員餘額（派發 bonus 獎金）
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            // 完成交易紀錄
            $this->finishDealRecord($dealRecord, $transactionLogId);

            // 更新 TotalBetRecord
            $this->createTotalBetRecord($betRecord, $transactionLogId, $walletDto);

            DB::commit();

            Log::info(sprintf('[%s] %s 成功 %s', $this->vendorCode, $action, $params['reference']), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'bet_record_id' => $betRecord->id,
                'campaign_id' => $params['campaignId'],
                'campaign_type' => $params['campaignType'],
                'promo_amount' => $amount,
            ]);

            // 重新查詢最新餘額
            $currentVendorBalance = $this->getVendorBalance();

            return response()->json([
                'error' => self::SUCCESS,
                'description' => 'Success',
                'transactionId' => $dealRecord->trace_id,
                'currency' => $this->account->currency,
                'cash' => $currentVendorBalance,
                'bonus' => 0,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error(sprintf('[%s] %s 失敗 %s：%s', $this->vendorCode, $action, $params['reference'], Str::limit($e->getMessage(), 97)), [
                'reference' => $params['reference'],
                'username' => $this->account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'error' => self::INTERNAL_ERROR,
                'description' => Str::limit($e->getMessage(), 97),
            ]);
        }
    }
}
