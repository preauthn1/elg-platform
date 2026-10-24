<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\EpicSwAccount;
use App\Vendors\Models\EpicSwBetRecord;
use App\Vendors\Models\EpicSwRecord;
use App\Vendors\WFSW\OperatorStatusEnum;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-epicwin
 */
class EpicWinController extends BaseController
{
    protected int $vendorId = VendorIdEnum::EPICSW->value;

    protected string $accountClass = EpicSwAccount::class;

    protected string $betRecordClass = EpicSwBetRecord::class;

    protected string $recordClass = EpicSwRecord::class;

    protected string $vendorInfoOperatorField = 'OPERATOR_ID';

    protected string $actionPrefix = 'EpicSw_get_request_';

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

            // 驗證簽名
            if ($this->checkAuthToken($request) === false) {
                return $this->errorResponse(OperatorStatusEnum::INVALID_PLAYER);
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
            $betId = $params['BetId'];
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
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', (string) $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'type' => 'N',
                'bet_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => '1970-01-01 00:00:00',
                'status' => 0,
            ]);
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

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
            $betId = $params['BetId'];
            $resultId = $params['ResultId'];
            $bet = Common::numDiv($params['BetAmount'], $this->vendorInfo->rate, 4);
            $validBet = Common::numDiv($params['ValidBetAmount'], $this->vendorInfo->rate, 4);
            $winLose = Common::numDiv($params['WinLose'], $this->vendorInfo->rate, 4);
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', (string) $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $validBet;
            $betRecord->win_lose = $winLose;
            // 已結算的注單只更新金額、保留首次結算時間：三方報表以首次結算歸日，
            // 覆寫會讓跨午夜補送的結果（如整點的 RoundType=1）被歸到隔天
            if ($oldBetRecord->status != 1) {
                $betRecord->payout_time = $time;
            }
            $betRecord->status = 1;
            $betRecord->result_id = $resultId;
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

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
            $betId = $params['BetId'];
            $win = 0;
            $winLose = 0;
            $bet = 0;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', (string) $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win_lose = $winLose;
            $betRecord->payout_time = $time;
            $betRecord->status = 0;
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

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
            $betId = $params['TranId'];
            $username = $params['PlayerId'];
            $gameCode = 'CashBonus';
            $bet = 0;
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $winLose = $win;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', (string) $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'type' => 'B',
                'bet_id' => $betId,
                'result_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => 1,
            ]);
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

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
            $betId = $params['TranId'];
            $username = $params['PlayerId'];
            $gameCode = 'Jackpot';
            $bet = 0;
            $win = Common::numDiv($params['Payout'], $this->vendorInfo->rate, 4);
            $winLose = $win;
            $time = $params['TranDateTime'];

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', (string) $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(OperatorStatusEnum::DUPLICATE_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'type' => 'J',
                'bet_id' => $betId,
                'result_id' => $betId,
                'username' => $username,
                'game_code' => $gameCode,
                'bet' => $bet,
                'win_lose' => $winLose,
                'rolling_chip' => 0,
                'info' => '[]',
                'bet_time' => $time,
                'payout_time' => $time,
                'status' => 1,
            ]);
            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

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

    private function checkSign(Request $request): bool
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

        $secKey = $this->vendorInfo->info['SECRET_KEY'] ?? '';

        if (empty($secKey)) {
            return false;
        }

        $signatureToValidate = md5($functionName . $betId . $requestDateTime . $operatorId . $secKey . $playerId);

        return $signature == $signatureToValidate;
    }

    private function checkAuthToken(Request $request)
    {
        if ($request->has('AuthToken')) {
            if ($this->account->session_token != $request->input('AuthToken')) {
                return false;
            }

            return true;
        }

        return true;
    }
}
