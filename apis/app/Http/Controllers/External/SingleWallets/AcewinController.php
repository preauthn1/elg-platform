<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\AcewinSW\BetErrorCodeEnum;
use App\Vendors\AcewinSW\BetRecordStatusEnum;
use App\Vendors\AcewinSW\ErrorCodeEnum;
use App\Vendors\AcewinSW\RollbackErrorCodeEnum;
use App\Vendors\Models\AcewinAccount;
use App\Vendors\Models\AcewinBetRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-acewin
 */
class AcewinController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::ACEWIN->value;

    protected string $accountClass = AcewinAccount::class;

    protected string $betRecordClass = AcewinBetRecord::class;

    protected string $vendorInfoOperatorField = 'CLIENT_AUTH_ACCOUNT';

    protected string $actionPrefix = 'ACEWIN_get_request_';

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
                return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'IP not in whitelist');
            }

            $params = $request->all();
            $operatorId = $this->parseOperatorId($request);

            // AcewinSW 的回調沒有簽名，商戶憑證走 HTTP Basic Auth（api.md 附錄 E），由下方 getVendorInfo()
            // 比對 CLIENT_AUTH_ACCOUNT——帶錯即回 Invalid client。api.md 標示的「可選」指的是這個機制可選擇啟用，
            // 不是三方可能不帶：生產 log 實證 TPower／Yes2Win 的回調一律帶 Authorization（target/op_tickets#3085）。
            // 空值不能交給 getVendorInfo()——它對空 operatorId 的語意是「退回該玩家所屬商戶」，會整段跳過比對，
            // 只剩 IP 白名單一道；故未帶憑證一律拒收，回應與憑證錯誤一致，並留 warning 供觀察三方行為。
            if ($operatorId === '') {
                Log::warning($this->actionPrefix . 'missing_client_credential', [
                    'vendor_id' => $this->vendorId,
                    'action' => $action,
                    'result' => 'rejected',
                ]);

                return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'Invalid client');
            }

            $token = [REDACTED]'token'] ?? '';
            $username = $params['userId'] ?? '';

            // 取得用戶, 有 username 則用 username 取得用戶, 沒有則用 token 取得用戶
            if (!empty($username)) {
                $this->member = $this->getMemberByThirdPartyUsername($username);
                if (!$this->member) {
                    return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'User not found');
                }
            } elseif (!empty($token)) {
                $this->member = $this->getMemberBySessionToken($token);
                if (!$this->member) {
                    return $this->errorResponse(ErrorCodeEnum::TOKEN_EXPIRED);
                }
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'Invalid client');
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

            return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'Internal server error');
        }
    }

    protected function parseOperatorId(Request $request): string
    {
        // 依據遊戲商需求調整，參數來源值
        $client_id = $request->header('Authorization'); // 取得 Authorization 標頭
        // 確認標頭存在且以 "Basic " 開頭
        if ($client_id && strpos($client_id, 'Basic ') === 0) {
            // 移除 "Basic " 前綴
            $client_id = substr($client_id, 6);
            // 解碼 Base64 字符串
            $client_id = base64_decode($client_id);

            return $client_id;
        }

        return '';
    }

    /**
     * 驗證 token 並取得餘額
     */
    public function auth(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(ErrorCodeEnum::OTHER_ERROR, 'User not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 投注+結算
     */
    public function bet(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 必須在鎖住會員之後才驗：REPEATABLE READ 的快照定在交易第一次一般讀，
            // 放在鎖之前會讀不到別人剛提交的會員／商戶狀態（target/tickets#4297）
            try {
                $this->verifyBettability((string) $params['game']);
            } catch (BetException) {
                return $this->errorResponse(BetErrorCodeEnum::OTHER_ERROR);
            }

            $balance = $walletDto->getTotalBalance();

            // 初始化所有變數
            // 必須轉字串：AcewinSW 的 round 是 JSON 數字，而 bet_id 是 varchar(191)。
            // 綁 int 會讓 MySQL 改以浮點數比較、整欄 CAST，唯一索引失效退化成全表掃描
            // （生產實測 1,086 萬列、單次查詢 16 秒，三方 5 秒逾時後無限重送）。
            $betId = (string) $params['round'];
            $gameCode = $params['game'];
            $bet = Common::numDiv($params['betAmount'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['winloseAmount'], $this->vendorInfo->rate, 4);
            $winLose = Common::numSub($win, $bet, 4);
            $time = Carbon::createFromTimestamp($params['wagersTime'])->toDateTimeString();

            // 檢查餘額
            if (Common::numCompare($balance, $bet) < 0) {
                return $this->errorResponse(BetErrorCodeEnum::INSUFFICIENT_BALANCE);
            }

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord) {
                return $this->errorResponse(BetErrorCodeEnum::REPEAT_TRANSACTION);
            }

            // 變化後的遊戲紀錄
            $betRecord = new $this->betRecordClass([
                'vendor_info_id' => $this->vendorInfo->id,
                'bet_id' => $betId,
                'vendor_username' => $this->account->vendor_username,
                'bet_time' => $time,
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => $winLose,
                'payout_time' => $time,
                'status' => BetRecordStatusEnum::SETTLE->value,
                'game_code' => $gameCode,
                'rolling_chip' => 0,
                'jackpot' => 0,
                'session_token' => $this->account->session_token,
                'info' => '[]',
            ]);

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($newBalance);
        });
    }

    /**
     * 撤單
     *
     * AcewinSW 在 bet 逾時（5 秒）後補送，每分鐘一次最多 10 次（api.md「cancelBet — 取消注單」節）。
     * 撤單語意是「這筆投注從未成立」，因此投注量與輸贏一律歸零——留著原金額會讓被撤銷的注單繼續
     * 以有效投注計入 SR11／SR12、三層代理返利與提款流水門檻（target/op_tickets#3078，與 AFBSW 的
     * target/op_tickets#3067 同型）。錢包退款交由 {@see changeBalance()} 以前後注單差額推得：真金回
     * 真金錢包、禮碼回禮碼錢包；修正前以請求金額覆寫既有金額，前後差額為零，等於完全沒退款。
     */
    public function cancelBet(Request $request)
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 初始化所有變數
            // 必須轉字串：AcewinSW 的 round 是 JSON 數字，而 bet_id 是 varchar(191)。
            // 綁 int 會讓 MySQL 改以浮點數比較、整欄 CAST，唯一索引失效退化成全表掃描
            // （生產實測 1,086 萬列、單次查詢 16 秒，三方 5 秒逾時後無限重送）。
            $betId = (string) $params['round'];
            $time = Carbon::now()->toDateTimeString();

            // 取得遊戲紀錄
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                return $this->errorResponse(RollbackErrorCodeEnum::BET_RECORD_NOT_FOUND);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::ROLLBACK->value) {
                return $this->errorResponse(RollbackErrorCodeEnum::REPEAT_TRANSACTION);
            }

            // 撤單金額與原注金不符時拒收：api.md 未定義部分撤單，注單金額以我方紀錄為準，不接受
            // 三方以請求金額覆寫（對齊 AFBSW refund 的處理），交由 AcewinSW 重送或人工處理
            $placedBet = Common::numAdd('0', (string) $oldBetRecord->bet, 4);
            $requestedBet = Common::numDiv(Common::numAdd('0', (string) ($params['betAmount'] ?? '0'), 4), $this->vendorInfo->rate, 4);
            if (Common::numCompare($requestedBet, $placedBet) !== 0) {
                Log::warning($this->actionPrefix . 'cancel_amount_mismatch', [
                    'bet_id' => $betId,
                    'placed_bet' => $placedBet,
                    'requested_bet' => $requestedBet,
                ]);

                return $this->errorResponse(RollbackErrorCodeEnum::INVALID_PARAMETER, 'Cancel amount mismatch');
            }

            // 變化後的遊戲紀錄
            $betRecord = clone $oldBetRecord;
            $betRecord->status = BetRecordStatusEnum::ROLLBACK->value;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = $time;

            // 紀錄請求資料
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $newBalance = $walletDto->getTotalBalance();

            return $this->response($newBalance);
        });
    }

    protected function response($balance, ErrorCodeEnum $status = ErrorCodeEnum::OK, string $description = '')
    {
        if (empty($description)) {
            $description = $status->name;
        }

        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'errorCode' => $status->value,
            'message' => $description,
            'username' => $this->account->username,
            'currency' => $this->vendorInfo->currency,
            'balance' => (float) $balance,
        ]);
    }

    protected function errorResponse(ErrorCodeEnum|RollbackErrorCodeEnum|BetErrorCodeEnum $status = ErrorCodeEnum::OK, string $description = '')
    {
        if (empty($description)) {
            $description = $status->name;
        }

        return response()->json([
            'errorCode' => $status->value,
            'message' => $description,
        ]);
    }
}
