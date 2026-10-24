<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\WfswAccount;
use App\Vendors\Models\WfswBetRecord;
use App\Vendors\WFSW\BetRecordStatusEnum;
use App\Vendors\WFSW\OperatorStatusEnum;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WFSW 單錢包回調。
 *
 * 本 class 沒有任何 validate()，`$request->all()` 的型別直達查詢條件，型別把關責任全在此。
 * WFSW 的 BetId／RoundId 以 JSON 數字送達（生產原文 `{"BetId": 191008158928, ...}`），而
 * wfsw_bet_records.bet_id 是 varchar：查詢條件一律先轉字串，禁止直接綁原值。綁整數會讓
 * MySQL 改以浮點數比較並逐列 CAST，唯一索引失效退化成全表掃描（target/tickets#4408；同型
 * 事故見 AcewinSW 的 MR !2281，生產實測單次查詢 16~42 秒、超過三方 5 秒逾時後無限重送）。
 * TranId（CashBonus／Jackpot）未取得生產樣本，一併轉字串以免重蹈覆轍。
 *
 * @knowledge rules/vendor-wf
 */
class WFController extends BaseController
{
    protected int $vendorId = VendorIdEnum::WFSW->value;

    protected string $accountClass = WfswAccount::class;

    protected string $betRecordClass = WfswBetRecord::class;

    protected string $vendorInfoOperatorField = 'OperatorId';

    protected string $actionPrefix = 'WFSW_get_request_';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(OperatorStatusEnum::INTERNAL_SERVER_ERROR, 'IP not in whitelist');
            }

            $params = $request->all();
            $operatorId = $params['OperatorId'] ?? '';
            $username = $params['PlayerId'] ?? '';

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_PLAYER);
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_OPERATOR_ID);
            }

            // 驗證簽名
            if ($this->checkSign($request) === false) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_SIGNATURE);
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
     * 查詢餘額
     */
    public function getBalance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::INVALID_PLAYER);
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->balanceResponse($balance);
    }

    /**
     * 投注
     */
    public function bet(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(OperatorStatusEnum::INVALID_PLAYER);
        }

        $params = $request->all();

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['GameCode']);
        if (!$ableToBet) {
            return $this->errorResponse(OperatorStatusEnum::PLAYER_INACTIVE);
        }

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = (string) $params['BetId'];
            $username = $params['PlayerId'];
            $gameCode = $params['GameCode'];
            $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
            $win = 0;
            $winLose = Common::numSub($win, $bet, 4);
            $time = $params['TranDateTime'];

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse(OperatorStatusEnum::INSUFFICIENT_BALANCE);
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'bet_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'valid_bet' => $bet,
                'payout' => $win,
                'win_lose' => $winLose,
                'rolling_chips' => 0,
                'infos' => '[]',
                'result_infos' => '[]',
                'bet_time' => $time,
                'payout_time' => '1970-01-01 00:00:00',
                'status' => BetRecordStatusEnum::BET->value,
            ]);
            // 紀錄請求資料
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
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

            // 初始化所有變數
            $betId = (string) $params['BetId'];
            $resultId = $params['ResultId'];
            $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
            $validBet = Common::numDiv($params['ValidBetAmount'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $winLose = Common::numDiv($params['WinLose'], $this->vendorInfo->rate, 4);
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::RESULT->value) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $validBet;
            $betRecord->payout = $win;
            $betRecord->win_lose = $winLose;
            $betRecord->payout_time = $time;
            $betRecord->status = BetRecordStatusEnum::RESULT->value; // 1:bet,2:result,3:rollback,4:bouns,5:jackpot
            $betRecord->result_id = $resultId;

            // 紀錄請求資料
            $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 取消投注
     */
    public function rollback(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = (string) $params['BetId'];
            $win = 0;
            $winLose = 0;
            $bet = 0;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::ROLLBACK->value) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->payout = $win;
            $betRecord->win_lose = $winLose;
            $betRecord->payout_time = $time;
            $betRecord->status = BetRecordStatusEnum::ROLLBACK->value; // 1:bet,2:result,3:rollback,4:bouns,5:jackpot

            // 紀錄請求資料
            $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * 活動獎金
     */
    public function bonus(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = (string) $params['TranId'];
            $username = $params['PlayerId'];
            $gameCode = 'CashBonus';
            $bet = 0;
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $winLose = $win;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'bet_id' => $betId,
                'result_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'payout' => $win,
                'win_lose' => $winLose,
                'rolling_chips' => 0,
                'infos' => '[]',
                'result_infos' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => BetRecordStatusEnum::BONUS->value,
            ]);

            // 紀錄請求資料
            $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['bonus' => $win]);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    /**
     * Jackpot獎金
     */
    public function jackpot(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = (string) $params['TranId'];
            $username = $params['PlayerId'];
            $gameCode = 'Jackpot';
            $bet = 0;
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $winLose = $win;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'bet_id' => $betId,
                'result_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'payout' => $win,
                'win_lose' => $winLose,
                'rolling_chips' => 0,
                'infos' => '[]',
                'result_infos' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => BetRecordStatusEnum::JACKPOT->value,
            ]);
            // 紀錄請求資料
            $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId, ['jackpot' => $win]);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($balance, $newBalance);
        });
    }

    protected function balanceResponse($balance, OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        if (empty($description)) {
            $description = $status->name;
        }

        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'Status' => $status->value,
            'Description' => $description,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
            'Balance' => (float) $balance,
        ]);
    }

    protected function response($oldBalance, $newBalance, OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        if (empty($description)) {
            $description = $status->name;
        }

        $oldBalance = Common::numMul($oldBalance, $this->vendorInfo->rate, 4);
        $newBalance = Common::numMul($newBalance, $this->vendorInfo->rate, 4);

        return response()->json([
            'Status' => $status->value,
            'Description' => $description,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
            'OldBalance' => (float) $oldBalance,
            'NewBalance' => (float) $newBalance,
        ]);
    }

    protected function errorResponse(OperatorStatusEnum $status = OperatorStatusEnum::OK, string $description = '')
    {
        if (empty($description)) {
            $description = $status->name;
        }

        return response()->json([
            'Status' => $status->value,
            'Description' => $description,
            'ResponseDateTime' => Carbon::now()->toDateTimeString(),
        ]);
    }

    protected function checkSign(Request $request): bool
    {
        $playerId = $request->input('PlayerId') ?? '';
        $operatorId = $request->input('OperatorId') ?? '';
        $signature = $request->input('Signature') ?? '';
        $requestDateTime = $request->input('RequestDateTime') ?? '';
        $functionName = trim(basename(url()->current()));

        switch ($functionName) {
            case 'Bet':
            case 'Rollback':
                $betId = $request->input('BetId') ?? '';
                break;
            case 'GameResult':
                $betId = $request->input('ResultId') ?? '';
                break;
            case 'CashBonus':
            case 'Jackpot':
                $betId = $request->input('TranId') ?? '';
                break;
            default:
                $betId = '';
        }

        $secKey = $this->vendorInfo->info['SecretKey'] ?? '';

        if (empty($secKey)) {
            return false;
        }

        $signatureToValidate = md5($functionName . $betId . $requestDateTime . $operatorId . $secKey . $playerId);

        return $signature == $signatureToValidate;
    }
}
