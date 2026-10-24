<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\PgsswAccount;
use App\Vendors\Models\PgsswBetRecord;
use App\Vendors\Models\PgsswCancelLog;
use App\Vendors\Models\PgsswEventRecord;
use App\Vendors\PegasusSW\BetRecordStatusEnum;
use App\Vendors\PegasusSW\OperatorStatusEnum;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-target
 */
class PegasusController extends BaseController
{
    /**
     * 回應給 target 的金額精度
     *
     * target 規格「金額最低至小數兩位、不支援小數三位以下」（docs/integrations/vendors/PegasusSW/api.md 通用約定）。
     * 送出超規位數時由 target 端自行截斷，行為不可控，故改由我方截到兩位。
     * numMul 走 bcmul 為無條件捨去，不可改成四捨五入：回報餘額大於實際餘額會讓玩家以該金額投注，
     * placeBet 撞到 403203 餘額不足後 target 會立即呼叫取消投注。
     */
    protected const BALANCE_SCALE = 2;

    /**
     * 有可能是 50(PGSSW) 或 91(TIGER9) 或 145(BWG), 會被覆寫
     */
    protected int $vendorId = VendorIdEnum::PGSSW->value;

    protected string $accountClass = PgsswAccount::class;

    protected string $betRecordClass = PgsswBetRecord::class;

    protected string $actionPrefix = 'PGSSW_get_request_';

    protected string $vendorInfoOperatorField = 'IDENTITY';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $params = $request->all();
            $identity = $request->header('X-PROVIDER-IDENTITY');
            $sessionToken = [REDACTED]'sessionToken'] ?? '';
            $username = $params['playerUniqueID'] ?? '';

            $action = $this->actionPrefix . basename(url()->current());
            $this->logRequest($request, $action);

            // 取得用戶, 若三方有傳 playerUniqueID, 則用三方 playerUniqueID, 不要用 sessionToken
            if (!empty($username)) {
                $this->member = $this->getMemberByThirdPartyUsername($username);
            } else {
                $this->member = $this->getMemberBySessionToken($sessionToken);
            }
            if (!$this->member) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE, 'Account not found');
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($identity, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE, 'Merchant not found');
            }

            // 這api 比較特別的是 vendor_id 有可能是 50(PGSSW) 或 91(TIGER9) 或 51(BWG)
            // 需要從 vendorInfo 覆寫 vendor_id
            $this->vendorId = $this->vendorInfo->vendor_id;

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR, 'IP not in whitelist');
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

            return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 這api 比較特別的是 vendor_id 有可能是 50(PGSSW) 或 91(TIGER9)
     * 因此 VendorInfo 不篩選 vendor_id
     */
    protected function getVendorInfo(string $operatorId = '', ?AbstractAccount $account = null): ?VendorInfo
    {
        $vendorInfos = VendorInfo::query()
            ->where('status', 1)
            ->when($account, function ($query) use ($account) {
                return $query->where('id', $account->vendor_info_id);
            })
            ->get();

        foreach ($vendorInfos as $vendorInfo) {
            if ($vendorInfo->info[$this->vendorInfoOperatorField] == $operatorId) {
                return $vendorInfo;
            }
        }

        return null;
    }

    /**
     * 查詢餘額
     */
    public function getBalance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE, 'Account not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 投注
     */
    public function placeBet(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE, 'Account not found');
        }
        $params = $request->all();

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gameID']);
        if (!$ableToBet) {
            return $this->response(0, -1, 'cannot bet');
        }

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $betId = $params['transactionNo'];
            $username = $params['playerUniqueID'];
            $gameCode = $params['gameID'];
            $sessionToken = [REDACTED]'sessionToken'];
            $bet = Common::numDiv($params['betAmount'], $this->vendorInfo->rate, 4);
            $win = 0;
            $winLose = Common::numSub($win, $bet, 4);
            $time = Carbon::now()->toDateTimeString();

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse(OperatorStatusEnum::INSUFFICIENT_BALANCE);
            }

            // 取得遊戲紀錄：重複檢查刻意不用 lockForUpdate——注單此時通常不存在，對不存在的
            // 唯一鍵做 FOR UPDATE 會拿 gap lock，兩筆並發下注落在同一個 gap 就會互相 deadlock；
            // 真正的防重是 bet_id 的 unique 索引（bonus／eventBonus／settleWithoutPayout 同理）
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'session_token' => $sessionToken,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win' => $win,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => '1970-01-01 00:00:00',
                'settlement_time' => '1970-01-01 00:00:00',
            ]);
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $betId);
        });
    }

    /**
     * 派彩/結算
     */
    public function settle(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $betId = $params['transactionNo'];
            // 一律使用有效流水 by mars
            $bet = Common::numDiv($params['validAmount'], $this->vendorInfo->rate, 4);
            $validBet = Common::numDiv($params['validAmount'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['payoutAmount'], $this->vendorInfo->rate, 4);
            $win_lose = Common::numSub($win, $bet, 4);
            $time = Carbon::parse($params['settlementTime'])->toDateTimeString();

            // 取得遊戲紀錄：鎖定讀才看得到等鎖期間才 commit 的注單（理由同 cancel()）
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->lockForUpdate()->first();

            if (!$oldBetRecord || BetRecordStatusEnum::BET->value != $oldBetRecord->status) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_STATUS);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = $win_lose;
            $betRecord->settlement_time = $time;
            $betRecord->payout_time = $time;
            $betRecord->status = BetRecordStatusEnum::SETTLE->value;
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['valid_bet' => $validBet]);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $betId);
        });
    }

    /**
     * 活動派彩
     * /target/transaction/event-bonus
     */
    public function eventBonus(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $betId = $params['transactionNo'];
            $username = $params['playerUniqueID'];
            $gameCode = 0;
            $sessionToken = [REDACTED]'sessionToken'] ?? '';
            $bet = 0;
            $win = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
            $win_lose = $win;
            $time = Carbon::parse($params['eventDetailInfo']['receiveTime'])->toDateTimeString();

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();
            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'session_token' => $sessionToken,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win' => $win,
                'win_lose' => $win_lose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'settlement_time' => $time,
            ]);
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['bonus' => $win]);

            // 紀錄活動派彩紀錄
            $this->createPgsswEventRecord($params);

            $newBalance = $walletDto->getTotalBalance();

            return $this->bonusResponse((float) $newBalance, $betId, (float) $balance, $betRecord->id);
        });

    }

    /**
     * 游戏结算(bonus獎勵／免費遊戲)
     * /target/bonus/settle
     */
    public function bonus(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $betId = $params['transactionNo'];
            $username = $params['playerUniqueID'];
            $gameCode = $params['gameID'];
            $sessionToken = [REDACTED]'sessionToken'] ?? '';
            $bet = 0;
            $win = Common::numDiv($params['payoutAmount'], $this->vendorInfo->rate, 4);
            $win_lose = $win;
            $time = Carbon::parse($params['settlementTime'])->toDateTimeString();

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'session_token' => $sessionToken,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win' => $win,
                'win_lose' => $win_lose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'settlement_time' => $time,
                // bonus 為一次性派彩完成，注單標記為已結算，與舊系統 API_External 相同
                'status' => BetRecordStatusEnum::SETTLE->value,
            ]);

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['bonus' => $win]);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($newBalance, $betId);
        });

    }

    /**
     * 取消
     */
    public function cancel(Request $request)
    {
        $params = $request->all();

        $cancelLog = $this->getOrCreatePgsswCancelLog($params);

        // 冪等保護：cancelLog 已處理過則直接回 OK
        if ($cancelLog->status != 0) {
            return response()->json([
                'code' => OperatorStatusEnum::OK->value,
                'data' => ['transactionNo' => $params['transactionNo']],
            ]);
        }

        return DB::transaction(function () use ($params, $cancelLog) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $betId = $params['transactionNo'];
            $bet = 0;
            $win = 0;
            $win_lose = 0;

            // 取得遊戲紀錄（同時比對 username，防止跨玩家沖帳）
            // 必須是鎖定讀：交易內只要在取得會員鎖之前有一般讀，REPEATABLE READ 快照就定在等鎖之前，
            // placeBet 在等鎖期間才 commit 的注單在快照裡看不到，一般讀會誤走「查無回 OK」，
            // 讓 target 以為已取消而不再重送，錢卻沒退。target/op_tickets#3075 TPower 事故即此，
            // 當時等鎖前的那筆一般讀是 $member->agent。
            $oldBetRecord = $this->betRecordClass::query()
                ->where('bet_id', $betId)
                ->where('username', $params['playerUniqueID'])
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                return $this->response($balance, $betId);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = $win_lose;
            $betRecord->status = BetRecordStatusEnum::ROLLBACK->value;
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            // 更新取消紀錄
            $cancelLog->status = 1;
            $cancelLog->save();

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $betId);
        });
    }

    /** @deprecated 此接口已廢棄，不再主動對接 */
    public function settleWithoutPayout(Request $request): \Illuminate\Http\JsonResponse
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $betId = $params['transactionNo'];
            $sessionToken = [REDACTED];
            $username = $params['playerUniqueID'];
            $gameCode = $params['gameID'];
            $bet = Common::numDiv($params['betAmount'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['payoutAmount'] ?? 0, $this->vendorInfo->rate, 4);
            $winLose = Common::numSub($win, $bet, 4);
            $time = Carbon::parse($params['settlementTime'])->toDateTimeString();

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();
            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'session_token' => $sessionToken,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win' => $win,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'settlement_time' => $time,
                'status' => BetRecordStatusEnum::SETTLE->value,
            ]);
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            // 原 API_Externel 規格：此通知純粹記錄注單，不改變餘額
            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, 0);

            return response()->json([
                'code' => OperatorStatusEnum::OK->value,
                'data' => ['transactionNo' => $betId],
            ]);
        });
    }

    public function settleChange(Request $request): \Illuminate\Http\JsonResponse
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 先鎖會員錢包、再鎖定讀注單：注單的鎖定讀若排在鎖錢包之前，會與 settle()
            // 形成「注單列 → 會員列」對「會員列 → 注單列」的鎖序倒轉；鎖定讀的理由同 cancel()
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $betId = $params['transactionNo'];
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->lockForUpdate()->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_NOT_FOUND);
            }

            $bet = Common::numDiv($params['afterBetAmount'], $this->vendorInfo->rate, 4);
            $validBet = Common::numDiv($params['afterValidAmount'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['afterPayoutAmount'], $this->vendorInfo->rate, 4);
            $winLose = Common::numSub($win, $bet, 4);
            $time = Carbon::parse($params['settlementTime'])->toDateTimeString();

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = $winLose;
            $betRecord->settlement_time = $time;
            $betRecord->payout_time = $time;
            $betRecord->status = BetRecordStatusEnum::SETTLE->value;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord, autoSave: false);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['valid_bet' => $validBet]);

            return response()->json([
                'code' => OperatorStatusEnum::OK->value,
                'data' => ['transactionNo' => $betId],
            ]);
        });
    }

    protected function errorResponse(OperatorStatusEnum $code, string $msg = '')
    {
        if (empty($msg)) {
            $msg = $code->name;
        }

        return response()->json([
            'code' => $code->value,
            'message' => $code->name,
            'data' => null,
        ]);
    }

    protected function response($balance, $transactionNo = null)
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, self::BALANCE_SCALE);
        $data = [
            'balance' => (float) $balance,
        ];

        if ($transactionNo) {
            $data['transactionNo'] = $transactionNo;
        }

        return response()->json([
            'code' => OperatorStatusEnum::OK->value,
            'data' => $data,
        ]);
    }

    /**
     * 活動派彩回應格式不同
     *
     * @param  float  $balance  變更後餘額
     * @param  string  $transactionNo  三方訂單號
     * @param  float  $beforeBalance  變更前餘額
     * @param  string  $merchantOrderNo  三方訂單號
     * @return \Illuminate\Http\JsonResponse
     */
    protected function bonusResponse($balance, $transactionNo, $beforeBalance, $merchantOrderNo)
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, self::BALANCE_SCALE);
        $beforeBalance = Common::numMul($beforeBalance, $this->vendorInfo->rate, self::BALANCE_SCALE);
        $data = [
            'transactionNo' => $transactionNo,
            'merchantOrderNo' => $merchantOrderNo,
            'beforeAmount' => (float) $beforeBalance,
            'afterAmount' => (float) $balance,
        ];

        return response()->json([
            'code' => OperatorStatusEnum::OK->value,
            'data' => $data,
        ]);
    }

    protected function createPgsswCancelLog($params): PgsswCancelLog
    {
        $data = [];
        $data['username'] = $params['playerUniqueID'];
        $data['transaction_no'] = $params['transactionNo'];
        $data['game_id'] = $params['gameID'];
        $data['status'] = 0; // 處理狀態 0:待處理,1:成功,2:失敗

        $result = PgsswCancelLog::create($data);

        return $result;
    }

    protected function getOrCreatePgsswCancelLog(array $params): PgsswCancelLog
    {
        return PgsswCancelLog::firstOrCreate(
            ['transaction_no' => $params['transactionNo']],
            [
                'username' => $params['playerUniqueID'],
                'game_id' => $params['gameID'],
                'status' => 0,
            ]
        );
    }

    protected function createPgsswEventRecord($params): PgsswEventRecord
    {
        $record = [];
        $record['bet_id'] = $params['transactionNo'];
        $record['username'] = $params['playerUniqueID'];
        $record['pay_time'] = Carbon::parse($params['eventDetailInfo']['receiveTime'])->toDateTimeString();
        $record['prize_amount'] = $params['amount'];
        $record['info'] = json_encode($params['eventDetailInfo']);

        $result = PgsswEventRecord::create($record);

        return $result;
    }
}
