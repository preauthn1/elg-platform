<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\General\ContextKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\Ace333SwAccount;
use App\Vendors\Models\Ace333SwBetRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-acewin
 */
class Ace333SWController extends BaseController
{
    protected int $vendorId = VendorIdEnum::ACE333SW->value;

    protected string $accountClass = Ace333SwAccount::class;

    protected string $betRecordClass = Ace333SwBetRecord::class;

    protected string $actionPrefix = 'Ace333SW_get_request_';

    protected string $vendorInfoOperatorField = 'CLIENT_ID'; // VendorInfo->info 中識別商戶的欄位

    protected string $playerIdOperatorField = 'vendor_uid'; // Ace333SwAccount 中對應 playerID 識別玩家的欄位

    protected string $requestId; // 錯誤的追蹤碼

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            $this->requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new ApiException('IP not in whitelist');
            }

            // 透過 token 查詢用戶
            $token = [REDACTED]'token');

            // api.md「認證方式（Token）」：Wallet API 回調必帶 header token、代理側須驗證。空值不能交給
            // getVendorInfo()——它對空 operatorId 的語意是「退回該玩家所屬商戶」，會整段跳過比對；
            // 未帶或為空一律拒收，與 token 錯誤走同一條回應（target/op_tickets#3085）
            if (!is_string($token) || $token === '') {
                throw new ApiException('Invalid token');
            }

            // ! authenticate 以外的請求都有 playerID
            if ($request->has('playerID')) {
                // 取得用戶
                $playerId = (string) $request->input('playerID');
                $this->member = $this->getMemberByThirdPartyUid($playerId);
                if (!$this->member) {
                    return response()->json([
                        'playerID' => $playerId,
                        'balance' => 0,
                        'error' => '1',
                        'description' => 'Player not found',
                    ]);
                }

                $this->vendorInfo = $this->getVendorInfo($token, $this->account);
            } elseif ($request->has('userName')) {
                // ! 只有 authenticate 用 userName 取得遊戲帳號
                // 取得用戶
                $userName = $request->input('userName');
                $this->member = $this->getMemberByThirdPartyUsername($userName);
                if (!$this->member) {
                    return response()->json([
                        'userName' => $userName,
                        'balance' => 0,
                        'error' => '1',
                        'description' => 'Player not found',
                    ]);
                }

                $this->vendorInfo = $this->getVendorInfo($token, $this->account);
            } else {
                $this->vendorInfo = $this->getVendorInfo($token);
            }

            if (!$this->vendorInfo) {
                throw new ApiException('Invalid token');
            }

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response
            return $response;

        } catch (\Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';

            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    protected function getMemberByThirdPartyUid(string $thirdPartyUid): ?Member
    {
        $account = (new $this->accountClass)::query()->where($this->playerIdOperatorField, $thirdPartyUid)->first();
        if (!$account || $account->member_id <= 0) {
            return null;
        }
        $this->account = $account;
        $member = Member::find($account->member_id);

        return $member;
    }

    protected function getMemberByThirdPartyUsername(string $thirdPartyUsername): ?Member
    {
        $account = (new $this->accountClass)::query()->where('username', $thirdPartyUsername)->first();
        if (!$account || $account->member_id <= 0) {
            return null;
        }
        $this->account = $account;
        $member = Member::find($account->member_id);

        return $member;
    }

    /**
     * 驗證用戶身份並返回餘額, 只有這個方法有自己的 response format
     */
    public function authenticate(Request $request)
    {
        if ($this->account->status == 0) {
            return response()->json([
                'userName' => $this->account->userName,
                'balance' => 0,
                'error' => '1',
                'description' => 'Player not found',
            ]);
        }
        $params = $request->validate([
            'userName' => 'required|string',
            'password' => 'required|string',
        ]);

        $this->member = $this->getMemberByThirdPartyUsername($params['userName']);

        // 驗證用戶身份
        if (!$this->member) {
            throw new ApiException('Invalid user');
        }

        /** @var Ace333SwAccount $account */
        $account = $this->account;
        if ($account->status == 0) {
            throw new ApiException('Account is disabled');
        }

        if ($params['password'] != $account->password) {
            throw new ApiException('Invalid user');
        }

        return DB::transaction(function () use ($account) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();
            $error = ($this->member->status == 1) ? '0' : '1';
            $description = ($this->member->status == 1) ? '' : 'Member is locked';

            return response()->json([
                'playerID' => $account->vendor_uid,
                'balance' => $balance,
                'error' => $error,
                'description' => $description,
            ]);
        });
    }

    // ! ==========================================

    // protected function errorResponse($playerId, string $description = '', $balance = 0)
    // {
    //     return response()->json([
    //         'playerID' => $playerId,
    //         'balance' => (float) $balance,
    //         'error' => '1',
    //         'description' => $description,
    //     ]);
    // }

    public function bet(Request $request)
    {
        if ($this->account->status == 0) {
            return response()->json([
                'userName' => $this->account->userName,
                'balance' => 0,
                'error' => '1',
                'description' => 'Player not found',
            ]);
        }
        $request->validate([
            'playerID' => 'required|numeric',
            'gameID' => 'required|string|max:20',           // ace333sw_bet_records.game_code
            'playSessionID' => 'required|string|max:100',   // ace333sw_bet_records.bet_id 局號
            'referenceID' => 'required|string|max:100',
            'betAmount' => 'required|numeric|max:9999999999.99|min:0',
            'created' => 'required|string',
        ]);

        $params = $request->all();

        $gameCode = $params['gameID'] !== 'JACKPOT' ? (int) $params['gameID'] : $params['gameID'];
        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $gameCode);
        if (!$ableToBet) {
            return response()->json([
                'transactionID' => $this->requestId,
                'balance' => 0,
                'error' => '1',
                'description' => 'Account could not bet yet',
            ]);
        }

        return DB::transaction(function () use ($params, $gameCode) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = $params['playSessionID'];
            $bet = Common::numDiv($params['betAmount'], $this->vendorInfo->rate, 4);
            $betTime = $params['created'];

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return response()->json([
                    'transactionID' => $this->requestId,
                    'balance' => (float) $balance,
                    'error' => '1',
                    'description' => 'Insufficient balance',
                ]);
            }

            // ! 允許同一筆投注紀錄被三方更新

            // 查詢遊戲紀錄是否存在
            $oldBetRecord = (new $this->betRecordClass)::query()
                ->where('bet_id', $betId)
                ->first();

            // 紀錄已存在
            // if ($hasBetRecord) {
            //     return $this->errorResponse($this->account->vendor_uid, 'Duplicate bet ID: ' . $betId, $balance);
            // }

            if (!$oldBetRecord) {
                // 創建一筆新紀錄
                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'bet_id' => $betId,
                    'vendor_username' => $this->account->vendor_username,
                    'bet_time' => now()->toDateTimeString(),
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win_lose' => 0,
                    'payout_time' => '1970-01-01 00:00:00', // 未結算
                    'status' => 0,
                    'game_code' => $gameCode,
                    'rolling_chip' => 0,
                    'session_token' => $this->account->session_token,
                    'info' => '[]',

                ]);
            } else {
                $betRecord = clone $oldBetRecord;
            }

            // 更新遊戲紀錄
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win_lose = Common::numSub(0, $bet, 4);
            $betRecord->bet_time = $betTime;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'transactionID' => $transactionLogId,
                'balance' => (float) $newBalance,
                'error' => '0',
                'description' => '',
            ]);
        });
    }

    /**
     * 結算派彩
     */
    public function settle(Request $request)
    {
        $request->validate([
            'playerID' => 'required|numeric',
            'gameID' => 'required|string|max:20',           // ace333sw_bet_records.game_code
            'playSessionID' => 'required|string|max:100',   // ace333sw_bet_records.bet_id 局號
            'referenceID' => 'required|string|max:100',
            'winAmount' => 'required|numeric|max:9999999999.99|min:0', // 派彩金額
            'created' => 'required|string',
        ]);

        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = $params['playSessionID'];
            $winLose = Common::numDiv($params['winAmount'], $this->vendorInfo->rate, 4);
            $payoutTime = $params['created'];

            // 取得遊戲紀錄
            $oldBetRecord = (new $this->betRecordClass)::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                return response()->json([
                    'transactionID' => $this->requestId,
                    'balance' => (float) $balance,
                    'error' => '1',
                    'description' => 'Bet record not found',
                ]);
            }

            // 更新遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->win_lose = Common::numSub($winLose, $betRecord->valid_bet);
            $betRecord->payout_time = $payoutTime;
            $betRecord->status = 1;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'transactionID' => $transactionLogId,
                'balance' => (float) $newBalance,
                'error' => '0',
                'description' => '',
            ]);
        });
    }

    /**
     * 獎金
     */
    public function jackpotWin(Request $request)
    {
        $request->validate([
            'playerID' => 'required|numeric',
            'gameID' => 'nullable|string|max:20',           // ace333sw_bet_records.game_code
            'playSessionID' => 'required|string|max:100',   // ace333sw_bet_records.bet_id 局號
            'referenceID' => 'required|string|max:100',
            'winAmount' => 'required|numeric|max:9999999999.99|min:0', // 派彩金額
            'created' => 'required|string',
        ]);

        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = $params['playSessionID'];
            $gameCode = isset($params['gameID']) ? (int) $params['gameID'] : 'JACKPOT';
            $winLose = Common::numDiv($params['winAmount'], $this->vendorInfo->rate, 4);
            $payoutTime = $params['created'];

            // 取得遊戲紀錄
            $oldBetRecord = (new $this->betRecordClass)::query()->where('bet_id', $betId)->first();

            // ! 如果遊戲紀錄已存在，則不允許重複結算
            if ($oldBetRecord) {
                $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

                return response()->json([
                    'transactionID' => $this->requestId,
                    'balance' => (float) $balance,
                    'error' => '1',
                    'description' => 'Duplicate playSessionID: ' . $betId,
                ]);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'vendor_username' => $this->account->vendor_username,
                'bet_time' => $payoutTime,
                'bet' => 0,
                'valid_bet' => 0,
                'win_lose' => 0,
                'jackpot' => $winLose,
                'payout_time' => $payoutTime, // 發彩金
                'status' => 1,
                'game_code' => $gameCode,
                'rolling_chip' => 0,
                'session_token' => $this->account->session_token,
                'info' => json_encode($params),
            ]);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'transactionID' => $transactionLogId,
                'balance' => (float) $newBalance,
                'error' => '0',
                'description' => '',
            ]);
        });
    }

    /**
     * 查詢餘額
     */
    public function getBalance()
    {
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'balance' => (float) $balance,
            'error' => '0',
            'description' => '',
        ]);
    }

    /**
     * 退款
     */
    public function refund(Request $request)
    {
        $request->validate([
            'playerID' => 'required|numeric',
            'gameID' => 'required|string|max:20',           // ace333sw_bet_records.game_code
            'playSessionID' => 'required|string|max:100',   // ace333sw_bet_records.bet_id 局號
            'referenceID' => 'required|string|max:100',
            'betRefundID' => 'required|string|max:100',
            'betAmount' => 'required|numeric|max:9999999999.99|min:0', // 投注失敗的退款金額
            'created' => 'required|string',
        ]);

        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = $params['betRefundID'];
            $payoutTime = $params['created'];

            // 取得遊戲紀錄
            $oldBetRecord = (new $this->betRecordClass)::query()->where('bet_id', $betId)->first();

            // ! 投注紀錄不存在
            if (!$oldBetRecord) {
                $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

                return response()->json([
                    'transactionID' => $this->requestId,
                    'balance' => (float) $balance,
                    'error' => '1',
                    'description' => 'Bet record not found',
                ]);
            }

            // ! 不允許重複退款
            if ($oldBetRecord->status == 9) {
                $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

                return response()->json([
                    'transactionID' => $this->requestId,
                    'balance' => (float) $balance,
                    'error' => '1',
                    'description' => 'Bet record already refunded',
                ]);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = $payoutTime;
            $betRecord->status = 9;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);
            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();
            $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

            return response()->json([
                'transactionID' => $transactionLogId,
                'balance' => (float) $newBalance,
                'error' => '0',
                'description' => '',
            ]);
        });
    }
}
