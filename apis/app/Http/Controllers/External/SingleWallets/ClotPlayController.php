<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\ClotswAccount;
use App\Vendors\Models\ClotswBetRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClotPlayController extends BaseController
{
    protected int $vendorId = VendorIdEnum::CLOTSW->value;

    protected string $accountClass = ClotswAccount::class;

    protected string $betRecordClass = ClotswBetRecord::class;

    protected string $actionPrefix = 'Clotsw_get_request_';

    protected string $vendorInfoOperatorField = 'agentCode';

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
                $this->response(0, -1, 'IP not in whitelist');
            }

            $params = $request->all();
            $agent_code = $params['agent_code'] ?? '';
            $authorizationKey = $request->header('Authorization');
            $username = $params['player_unique_id'];

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->response(0, -1, 'member not found');
            }

            // 取得商戶 需要放在取得用戶後面
            $this->vendorInfo = $this->getVendorInfo($agent_code, $this->account);
            if (!$this->vendorInfo) {
                return $this->response(0, -1, 'agent_code error');
            }

            // 驗證簽名
            if (!$this->checkSign($authorizationKey)) {
                return $this->response(0, -1, 'authorization error');
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

            return $this->response(0, -1, 'system error');
        }
    }

    /**
     * 查詢餘額
     */
    public function balance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->response(0, -1, 'member not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 投注
     * - v2版本的api文件新增的 api
     */
    public function bet(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->response(0, -1, 'member not found');
        }
        $params = $request->all();

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['game']);
        if (!$ableToBet) {
            return $this->response(0, -1, 'cannot bet');
        }

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $bet = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->response($balance, -1, 'insufficient balance');
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['game_round_id'])->first();

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['game_round_id'],
                    'username' => $params['player_unique_id'],
                    'game_code' => $params['game'],
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
            }

            // 更新遊戲紀錄
            $win = 0;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = Carbon::parse($params['created_at'])->timezone('UTC')->format('Y-m-d H:i:s');

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance);
        });
    }

    /**
     * 派彩
     * - v2版本的api文件新增的 api
     */
    public function settle(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['game_round_id'])->first();

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['game_round_id'],
                    'username' => $params['player_unique_id'],
                    'game_code' => $params['game'],
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
            }

            // 更新遊戲紀錄
            $bet = Common::numDiv($params['valid_bet'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = Carbon::parse($params['created_at'])->timezone('UTC')->format('Y-m-d H:i:s');
            $betRecord->payout_time = Carbon::parse($params['updated_at'])->timezone('UTC')->format('Y-m-d H:i:s');

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance);
        });
    }

    /**
     * 錢包加扣點
     */
    public function betAndSettle(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $bet = Common::numDiv($params['bet'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['win'], $this->vendorInfo->rate, 4);

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['game_round_id'])->first();

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['game_round_id'],
                    'username' => $params['player_unique_id'],
                    'game_code' => $params['game'],
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
            }

            // 更新遊戲紀錄
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = Carbon::parse($params['created_at'])->timezone('UTC')->format('Y-m-d H:i:s');
            $betRecord->payout_time = Carbon::parse($params['updated_at'])->timezone('UTC')->format('Y-m-d H:i:s');

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance);
        });
    }

    /**
     * 取消
     */
    public function cancel(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['game_round_id'])->first();

            // 沒有遊戲紀錄不用進行回滾 直接回傳餘額
            if (!$oldBetRecord) {
                return $this->response($balance);
            }
            // 已經回滾過的直接回傳餘額
            if ($oldBetRecord->settle_status == 2) {
                return $this->response($balance);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win = 0;
            $betRecord->win_lose = 0;
            $betRecord->settle_status = 2;

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance);
        });
    }

    protected function checkSign($authorizationKey)
    {
        return $this->vendorInfo->info['operatorToken'] == $authorizationKey;
    }

    protected function response($balance, $code = 0, $msg = 'success')
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'code' => $code,
            'msg' => $msg,
            'data' => [
                'balance' => $balance,
            ],
        ]);
    }
}
