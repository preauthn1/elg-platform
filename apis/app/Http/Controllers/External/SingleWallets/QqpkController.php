<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\QqpkswAccount;
use App\Vendors\Models\QqpkswBetRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QqpkController extends BaseController
{
    protected int $vendorId = VendorIdEnum::QQPK->value;

    protected string $accountClass = QqpkswAccount::class;

    protected string $betRecordClass = QqpkswBetRecord::class;

    protected string $actionPrefix = 'Qqpksw_get_request_';

    protected string $vendorInfoOperatorField = '';

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
                return $this->errorResponse('IP not in whitelist');
            }

            $params = $request->all();
            $username = $params['account'];

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse('member not found');
            }

            // 取得商戶 需要放在取得用戶後面
            $this->vendorInfo = $this->getVendorInfo('', $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse('vendor info not found');
            }

            // 驗證簽名
            if (!$this->checkSign($params)) {
                return $this->errorResponse('sign error');
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

    /**
     * 查詢餘額
     */
    public function balance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse('member not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 投注
     */
    public function debit(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->response(0, -1, 'member not found');
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['kindid']);
        if (!$ableToBet) {
            return $this->response(0, -1, 'cannot bet');
        }

        $params = $request->all();
        $win = '0';
        $bet = Common::numMul($params['money'], '-1');
        $bet = Common::numDiv($bet, $this->vendorInfo->rate, 4);

        return DB::transaction(function () use ($params, $bet, $win) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse('insufficient balance');
            }

            // 取得遊戲紀錄（注單號 etransid）
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['etransid'])->first();
            if ($oldBetRecord) {
                return $this->errorResponse('etransid repeat');
            }

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['etransid'],
                    'bet_group_id' => $params['etransgroup'],
                    'username' => $params['account'],
                    'game_code' => (string) $params['kindid'],
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'opcode' => $params['opcode'],
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
                $betRecord->bet_group_id = $params['etransgroup'];
                $betRecord->game_code = (string) $params['kindid'];
            }

            // 更新遊戲紀錄
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = Carbon::createFromTimestamp($params['time'])->timezone('UTC')->toDateTimeString();
            $betRecord->payout_time = $betRecord->bet_time;

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $params['money']);
        });
    }

    /**
     * 派彩
     */
    public function credit(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->response(0, -1, 'member not found');
        }
        $params = $request->all();
        $bet = 0;
        $win = Common::numDiv($params['money'], $this->vendorInfo->rate, 4);

        return DB::transaction(function () use ($params, $bet, $win) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 取得遊戲紀錄（注單號 etransid）
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['etransid'])->first();
            if ($oldBetRecord) {
                return $this->errorResponse('etransid repeat');
            }

            // 變化後的遊戲紀錄
            if (!$oldBetRecord) {
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $params['etransid'],
                    'bet_group_id' => $params['etransgroup'],
                    'username' => $params['account'],
                    'game_code' => (string) $params['kindid'],
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    'rolling_chip' => 0,
                    'opcode' => $params['opcode'],
                    'info' => '[]',
                    'bet_time' => '1970-01-01 00:00:00',
                    'payout_time' => '1970-01-01 00:00:00',
                ]);
            } else {
                $betRecord = clone $oldBetRecord;
                $betRecord->bet_group_id = $params['etransgroup'];
                $betRecord->game_code = (string) $params['kindid'];
            }

            // 更新遊戲紀錄
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = Carbon::createFromTimestamp($params['time'])->timezone('UTC')->toDateTimeString();
            $betRecord->payout_time = $betRecord->bet_time;

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $params['money']);
        });
    }

    protected function checkSign(array $params)
    {
        // 过滤掉值为 null 的键值对
        $filtered = array_filter($params, static function ($value): bool {
            return $value !== null;
        });

        $sign = $params['sign'];

        // 移除名为 'sign' 的键
        unset($filtered['sign']);

        // 按照键名的字母顺序排序
        ksort($filtered);

        // 生成查询字符串
        $qs = '';
        foreach ($filtered as $k => $v) {
            $qs .= "$k=$v&";
        }

        // 移除尾部的 '&' 符号
        $qs = rtrim($qs, '&');

        // 将查询字符串生成 MD5 哈希并转换为大写
        $md5 = strtoupper(md5($qs));

        return $md5 == $sign;
    }

    protected function errorResponse(string $msg = 'system error')
    {
        return response()->json([
            'status_code' => 500,
            'message' => $msg,
        ]);
    }

    protected function response($balance, $money = null)
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        $data = [
            'account' => $this->account->username,
            'currency' => $this->vendorInfo->info['currency'],
            'balance' => (float) $balance,
        ];

        if ($money) {
            $data['money'] = (float) $money;
        }

        return response()->json([
            'status_code' => 200,
            'message' => 'success',
            'data' => $data,
        ]);
    }
}
