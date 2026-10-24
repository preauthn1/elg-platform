<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\ImpswAccount;
use App\Vendors\Models\ImpswBetRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImperiumController extends BaseController
{
    protected int $vendorId = VendorIdEnum::IMPSW->value;

    protected string $accountClass = ImpswAccount::class;

    protected string $betRecordClass = ImpswBetRecord::class;

    protected string $actionPrefix = 'Impsw_get_request_';

    protected string $vendorInfoOperatorField = 'hallId';

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
                $this->errorResponse('IP not in whitelist');
            }

            $params = $request->all();
            $hallId = $params['hall'] ?? '';
            $hallKey = $params['key'] ?? '';
            $username = $params['login'];

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse('member not found');
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($hallId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse('hall id error');
            }

            // 驗證簽名
            if (!$this->checkSign($hallKey)) {
                return $this->errorResponse('hall key error');
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

            return $this->errorResponse('system error');
        }
    }

    public function entrance(Request $request)
    {
        $params = $request->all();
        $cmd = $params['cmd'] ?? '';

        switch ($cmd) {
            case 'getBalance':
                return $this->balance($params);
            case 'writeBet':
                return $this->settle($params);
        }

        return $this->errorResponse('cmd not found');
    }

    /**
     * 查詢餘額
     */
    public function balance(array $params)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse('member not found');
        }
        $playerAccount = $params['login'];
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance, $playerAccount);
    }

    /**
     * 錢包加扣點
     */
    public function settle(array $params)
    {
        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得金額變化
            $bet = Common::numDiv($params['bet'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['win'], $this->vendorInfo->rate, 4);

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse('fail_balance');
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['tradeId'])->first();

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['tradeId'],
                    'username' => $params['login'],
                    'game_code' => $params['gameId'],
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
            $betRecord->bet_time = Carbon::parse($params['date'])->timezone('UTC')->format('Y-m-d H:i:s');
            $betRecord->payout_time = Carbon::parse($params['date'])->timezone('UTC')->format('Y-m-d H:i:s');

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            $playerAccount = $params['login'];

            return $this->response($balance, $playerAccount);
        });
    }

    protected function checkSign($hallKey)
    {
        return $this->vendorInfo->info['hallKey'] == $hallKey;
    }

    protected function response($balance, $username)
    {
        // 這遊戲商只需要2位小數
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 2);

        return response()->json([
            'status' => 'success',
            'error' => '',
            'login' => $username,
            'balance' => $balance,
            'currency' => $this->vendorInfo->currency ?? '',
        ]);
    }

    protected function errorResponse(string $msg)
    {
        return response()->json([
            'status' => 'fail',
            'error' => $msg,
        ]);
    }
}
