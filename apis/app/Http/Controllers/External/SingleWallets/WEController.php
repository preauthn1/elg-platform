<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\WeswAccount;
use App\Vendors\Models\WeswBetRecord;
use App\Vendors\WESW\WeswBetRecordStatusEnum;
use App\Vendors\WESW\WeswErrorCodeEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WEController extends BaseController
{
    protected int $vendorId = VendorIdEnum::WESW->value;

    protected string $accountClass = WeswAccount::class;

    protected string $betRecordClass = WeswBetRecord::class;

    protected string $vendorInfoOperatorField = 'operatorId';

    protected string $actionPrefix = 'WESW_get_request_';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();
            $endpoint = basename(url()->current());
            $action = $this->actionPrefix . $endpoint;

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(WeswErrorCodeEnum::INTERNAL_SERVER_ERROR, 'IP not in whitelist');
            }

            $params = $request->all();

            // 派彩或重新結算的話, 會一次傳進來同個用戶多筆遊戲紀錄, 取第一筆就好
            if ($endpoint == 'credit' || $endpoint == 'resettlement') {
                $data = json_decode($params['data'], true);
                $params = $data[0];
            }

            $operatorId = $params['operatorID'];
            $appSecret = [REDACTED]'appSecret'];
            $userToken = [REDACTED]'token'] ?? '';
            $username = $params['playerID'] ?? '';

            // 取得用戶
            if (!empty($userToken)) {
                $username = $this->decryptToken($userToken);
                if (empty($username)) {
                    return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
                }
            }

            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(WeswErrorCodeEnum::CERTIFICATE_ERROR);
            }

            // 驗證簽名
            if ($this->checkSign($appSecret) === false) {
                return $this->errorResponse(WeswErrorCodeEnum::CERTIFICATE_ERROR);
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

            return $this->errorResponse(WeswErrorCodeEnum::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 驗證玩家
     */
    public function validate(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
        }

        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();
        $balance = (int) Common::numMul($balance, $this->vendorInfo->rate, 0);

        return response()->json([
            'playerID' => $this->account->username, // string 玩家ID
            'nickname' => $this->account->username, // string 暱稱
            'currency' => $this->vendorInfo->currency, // string 幣別
            'time' => time(), // int 時間 ( UNIX )
            'balance' => $balance, // int 玩家餘額 ( 單位:分 )
        ]);
    }

    /**
     * 查詢餘額
     */
    public function balance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 餘額扣款
     */
    public function debit(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
        }
        $params = $request->all();

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gameID']);
        if (!$ableToBet) {
            return $this->errorResponse(WeswErrorCodeEnum::INTERNAL_SERVER_ERROR);
        }

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            $betId = $params['betID'];
            $username = $this->account->username;
            $gameId = $params['gameID'];
            $gameCode = 'TP01'; // 在我方DB只有一款遊戲
            $gameRoundId = $params['gameRoundID'];
            $type = $params['type'];
            $bet = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);

            $win = 0;
            $winLose = Common::numSub($win, $bet, 4);
            $time = date('Y-m-d H:i:s', $params['time']);

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse(WeswErrorCodeEnum::INSUFFICIENT_BALANCE);
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(WeswErrorCodeEnum::REPEAT_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'bet_id' => $betId,
                'ref_id' => $betId, // 運營商遊戲紀錄ID, 新增資料後會改成id, 這裡不留空是因為有唯一鍵
                'username' => $username,
                'game_id' => $gameId,
                'game_round_id' => $gameRoundId,
                'status' => WeswBetRecordStatusEnum::DEBIT->value,
                'type' => $type,
                'game_code' => $gameCode,
                'bet' => $bet,
                'valid_bet' => $bet,
                'payout' => $win,
                'win_lose' => $winLose,
                'rolling_chips' => 0,
                'bet_time' => $time,
                'payout_time' => '1970-01-01 00:00:00',
                'infos' => '[]',
                'result_infos' => '[]',
            ]);
            // 紀錄請求資料
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 更新 ref_id
            $betRecord->ref_id = date('YmdHis', $params['time']) . $username . str_pad($betRecord->id, 8, '0', STR_PAD_LEFT);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($newBalance, $betRecord->ref_id);
        });
    }

    /**
     * 單次派彩
     */
    protected function settle(array $params, WeswBetRecordStatusEnum $status)
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = $walletDto->getTotalBalance();

        // 初始化所有變數
        $betId = $params['betID'];
        $type = $params['type'];
        $win = Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
        $validBet = Common::numDiv($params['validBetAmount'], $this->vendorInfo->rate, 4); // 跟 bet 的值可能不同
        $time = date('Y-m-d H:i:s', $params['time']);

        // 取得遊戲紀錄
        $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

        if (!$oldBetRecord) {
            return $this->errorResponse(WeswErrorCodeEnum::BET_RECORD_NOT_FOUND);
        }

        if ($status->value == $oldBetRecord->status) {
            return $this->errorResponse(WeswErrorCodeEnum::REPEAT_TRANSACTION);
        }

        $winLose = Common::numSub($win, $oldBetRecord->bet, 4);

        // 變化後的遊戲紀錄
        $betRecord = clone $oldBetRecord;
        $betRecord->status = $status->value;
        $betRecord->type = $type;
        $betRecord->valid_bet = $validBet;
        $betRecord->payout = $win;
        $betRecord->win_lose = $winLose;
        $betRecord->payout_time = $time;

        // 紀錄請求資料
        $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

        $betRecord->save();

        // 寫入全部遊戲紀錄
        $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        $totalBetRecordService->create($betRecord, $transactionLogId);

        $newBalance = $walletDto->getTotalBalance();

        return $this->response($newBalance, $betRecord->ref_id);
    }

    /**
     * 增加餘額
     * 會一次傳進來同個用戶多筆遊戲紀錄, 所以需要迴圈處理
     */
    public function credit(Request $request)
    {
        $params = $request->all();
        $data = json_decode($params['data'], true);

        // 檢查有沒有不同用戶
        foreach ($data as $row) {
            if ($row['playerID'] != $this->account->username) {
                return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
            }
        }

        return DB::transaction(function () use ($data) {
            // 取得用戶真金跟禮碼
            $return = null;
            foreach ($data as $params) {
                $return = $this->settle($params, WeswBetRecordStatusEnum::CREDIT);
            }

            return $return;
        });
    }

    /**
     * 重新結算
     * 會一次傳進來同個用戶多筆遊戲紀錄, 所以需要迴圈處理
     */
    public function resettlement(Request $request)
    {
        $params = $request->all();
        $data = json_decode($params['data'], true);

        // 檢查有沒有不同用戶
        foreach ($data as $row) {
            if ($row['playerID'] != $this->account->username) {
                return $this->errorResponse(WeswErrorCodeEnum::USER_NOT_FOUND);
            }
        }

        return DB::transaction(function () use ($data) {
            // 取得用戶真金跟禮碼
            $return = null;
            foreach ($data as $params) {
                $return = $this->settle($params, WeswBetRecordStatusEnum::RESETTLEMENT);
            }

            return $return;
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
            $betId = $params['betID'];
            $type = $params['type'];
            $bet = 0;
            $win = 0;
            $validBet = 0;
            $winLose = 0;
            $time = date('Y-m-d H:i:s', $params['time']);

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(WeswErrorCodeEnum::BET_RECORD_NOT_FOUND);
            }

            if (WeswBetRecordStatusEnum::ROLLBACK->value == $oldBetRecord->status) {
                return $this->errorResponse(WeswErrorCodeEnum::REPEAT_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->status = WeswBetRecordStatusEnum::ROLLBACK->value;
            $betRecord->type = $type;
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $validBet;
            $betRecord->payout = $win;
            $betRecord->win_lose = $winLose;

            // 紀錄請求資料
            $betRecord->result_infos = $this->appendParamsToInfo($betRecord->result_infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($newBalance, $betRecord->ref_id);
        });
    }

    protected function response($balance, string $refId = '')
    {
        $balance = (int) Common::numMul($balance, $this->vendorInfo->rate, 0);

        $data = [
            'balance' => $balance, // int 玩家餘額 ( 單位:分 )
            'currency' => $this->vendorInfo->currency, // string 幣別
            'time' => time(), // int 時間 ( UNIX )
        ];

        if (!empty($refId)) {
            $data['refID'] = $refId;
        }

        return response()->json($data);
    }

    protected function errorResponse(WeswErrorCodeEnum $code, string $description = '')
    {
        if (empty($description)) {
            $description = $code->name;
        }

        return response()->json(['error' => $description], $code->value);
    }

    protected function checkSign(string $appSecret)
    {
        return $this->vendorInfo->info['appSecret'] == $appSecret;
    }

    public function genToken(Request $request): string
    {
        if (config('app.env') == 'production') {
            return '';
        }

        $username = $request->input('playerID');

        // 创建初始化向量（IV）
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivlen);

        // 加密数据
        $ciphertext = openssl_encrypt($username, 'AES-256-CBC', $this->vendorInfo->info['appSecret'], 0, $iv);

        // 将密文和初始化向量一起传递
        $ciphertextWithIV = base64_encode($iv . $ciphertext);

        return $ciphertextWithIV;
    }

    /**
     * 解密 token
     *
     * token 是會員進入遊戲前，前台開遊戲流程加密的 username。
     * 它由 WESW Driver 的 getToken() 產生：以 appSecret 做 AES-256-CBC，初始化向量放在密文前面。
     */
    private function decryptToken($data): string
    {
        // 解密数据
        $ciphertextWithIV = base64_decode($data);

        // 分离初始化向量和密文
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($ciphertextWithIV, 0, $ivlen);
        $ciphertext = substr($ciphertextWithIV, $ivlen);

        $result = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->vendorInfo->info['appSecret'], 0, $iv);

        if ($result === false) {
            return '';
        }

        return $result;
    }
}
