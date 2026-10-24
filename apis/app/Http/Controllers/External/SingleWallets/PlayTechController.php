<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Exceptions\LockTimeoutException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\Member;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use App\Vendors\PlayTechSW\GameRoundLock;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * @knowledge rules/vendor-ptsw
 */
class PlayTechController extends BaseController
{
    protected int $vendorId = VendorIdEnum::PTSW->value;

    protected string $accountClass = PtswAccount::class;

    protected string $betRecordClass = PtswBetRecord::class;

    protected string $dealRecordModel = PtswDealRecord::class;

    protected string $actionPrefix = 'PTSW_request_';

    protected string $vendorInfoOperatorField = '';

    protected string $requestId = '';

    /*
    |--------------------------------------------------------------------------
    | 請求前置處理
    |--------------------------------------------------------------------------
    */

    /**
     * 所有 endpoint 的統一前置：基本欄位驗證、IP 白名單、會員／商戶載入、
     * token 比對，以及未被各 endpoint 承接之例外的統一兜底（INTERNAL_ERROR）。
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            $request = request();

            // 最低欄位檢查（若不同 endpoint 規則不同可細分）
            $validator = Validator::make($request->all(), [
                'requestId' => 'required|string',
                'username' => 'required|string',
                // externalToken 在某些 endpoint 必要，下面再做條件檢查
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('INTERNAL_ERROR', $validator->errors()->first());
            }

            $this->requestId = $request->input('requestId');
            $endpoint = basename(url()->current());
            $action = $this->actionPrefix . $endpoint;
            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse('INTERNAL_ERROR', 'IP not in whitelist');
            }

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($request->input('username'));
            if (!$this->member) {
                return $this->errorResponse('ERR_PLAYER_NOT_FOUND', 'Member not found');
            }

            if (!isset($this->account) || !is_object($this->account)) {
                Log::warning('PlayTech missing account', ['username' => $request->input('username')]);

                return $this->errorResponse('INTERNAL_ERROR', 'Account not found');
            }

            // 取得商戶 需要放在取得用戶後面
            $this->vendorInfo = $this->getVendorInfo('', $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse('INTERNAL_ERROR', 'Vendor info not found');
            }

            // token 檢查：只有在需要時比對，並使用 hash_equals 防 timing attack
            $checkEndpoint = ['auth', 'bet', 'balance', 'keepAlive', 'logout'];
            if (in_array($endpoint, $checkEndpoint, true)) {
                $token = [REDACTED]'externalToken');
                $accountToken = [REDACTED] ?? null;

                if (!is_string($token) || !is_string($accountToken) || !hash_equals($accountToken, $token)) {
                    return $this->errorResponse('ERR_AUTHENTICATION_FAILED', 'Token is invalid');
                }
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

            return $this->errorResponse('INTERNAL_ERROR', 'System internal error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 三方 API 端點
    |--------------------------------------------------------------------------
    | 會話類：auth / balance / keepAlive / notifyBonusEvent / logout
    | 交易類：bet / settlement / transferFunds（骨架見「共用骨架」區段）
    */

    /**
     * 玩家登入認證：externalToken 已於 callAction 驗證，此處回傳玩家基本資料
     */
    public function auth()
    {
        if ($this->account->status == 0) {
            return $this->errorResponse('ERR_PLAYER_NOT_FOUND', 'Member not found');
        }

        $responseData = [
            'requestId' => $this->requestId,
            'username' => $this->account->username,
            'currencyCode' => $this->vendorInfo->currency,
            'countryCode' => $this->vendorInfo->info['COUNTRY_CODE'],
        ];

        // 僅在 VIP level 存在時才回傳
        $vipLevel = $this->vendorInfo->info['VIP_LEVEL'] ?? null;
        if ($vipLevel !== null) {
            $responseData['subBrand'] = $vipLevel;
        }

        return response()->json($responseData);
    }

    /**
     * 查詢餘額
     */
    public function balance()
    {
        if ($this->account->status == 0) {
            return $this->errorResponse('ERR_PLAYER_NOT_FOUND', 'Member not found');
        }
        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->response($balance);
    }

    /**
     * 保持玩家登入會話活動
     */
    public function keepAlive()
    {
        return response()->json([
            'requestId' => $this->requestId,
        ]);
    }

    /**
     * 通知運營商獎金實例事件（目前支援 ACCEPTED / REMOVED），僅回應不處理
     */
    public function notifyBonusEvent()
    {
        return response()->json([
            'requestId' => $this->requestId,
        ]);
    }

    /**
     * 登出：使外部令牌（externalToken）失效
     */
    public function logout()
    {
        $this->account->token = [REDACTED];
        $this->account->save();

        return response()->json([
            'requestId' => $this->requestId,
        ]);
    }

    /**
     * 投注：同局（gameRoundCode）允許多次下注、注單累加；
     * 經 runLockedDealAction 序列化同局併發並處理冪等
     */
    public function bet(Request $request)
    {
        $rules = [
            'gameRoundCode' => 'required|string',
            'gameCodeName' => 'required|string',
            'transactionCode' => 'required|string',
            'amount' => 'required|numeric',
            'transactionDate' => 'required|date',
            'requestId' => 'required|string',
            'username' => 'required|string',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->errorResponse('INTERNAL_ERROR', $validator->errors()->first());
        }

        $params = $request->all();

        // 投注前應檢查遊戲是否存在
        $gameCode = Str::startsWith($params['gameRoundCode'], 'live_') ? 'ubal' : $params['gameCodeName'];
        $game = $this->getGameByCode($gameCode);
        if (!$game) {
            return $this->errorResponse('ERR_TRANSACTION_DECLINED', 'The gameCodeName is not supported');
        }

        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $game);
        if (!$ableToBet) {
            return $this->errorResponse('ERR_TRANSACTION_DECLINED', 'Account could not bet yet');
        }

        return $this->runLockedDealAction('bet', $params, function (PtswDealRecord $dealRecord) use ($params, $gameCode) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            // 冪等去重：已存在相同交易碼，表示已處理過，直接回傳現有注單
            if ($this->hasAppliedDealTransaction($dealRecord)) {
                Log::warning('PTSW 重複交易碼，交易已處理過', ['bet_id' => $dealRecord->bet_id, 'transaction_code' => $dealRecord->transaction_code]);

                return $this->response($balance, $dealRecord);
            }

            [$oldBetRecord, $betRecord] = $this->firstOrNewBetRecord($params, $gameCode);
            $winAmount = $oldBetRecord ? $betRecord->win_lose : '0';

            $betAmount = Common::numDiv(abs($params['amount']), $this->vendorInfo->rate, 4); // 下注金额轉換匯率

            if (Common::numCompare($betAmount, $balance) > 0) {
                return $this->errorResponse('ERR_INSUFFICIENT_FUNDS', 'Insufficient funds');
            }

            // 更新遊戲紀錄
            // PTSW live casino 允許同一 gameRoundCode 多次下注（21 點 split/保險、百家樂多手）；
            // bet / valid_bet 必須累加。新建時 BR.bet=0、cloned 時帶舊累計值。
            $betRecord->bet = Common::numAdd($betRecord->bet, $betAmount, 4);
            $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $betAmount, 4);
            $betRecord->win_lose = Common::numSub($winAmount, $betAmount, 4);
            $betRecord->bet_time = Carbon::parse($params['transactionDate'])->toSystemTime()->toDateTimeString();

            // 標記 PtswDealRecord 已處理
            $dealRecord->update([
                'applied_at' => Carbon::now(),
            ]);

            $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            return $this->response($walletDto->getTotalBalance(), $dealRecord);
        });
    }

    /**
     * 派彩結算：含退款（pay.type=REFUND）與回合關閉（gameRoundClose）；
     * 經 runLockedDealAction 序列化同局併發並處理冪等
     */
    public function settlement(Request $request)
    {
        $rules = [
            'gameRoundCode' => 'required|string',
            'pay.type' => 'required_with:pay|string',
            'pay.amount' => 'required_with:pay|numeric',
            'pay.transactionCode' => 'required_with:pay|string',
            'pay.transactionDate' => 'required_if:pay.type,REFUND|date',
            'jackpot.winAmount' => 'required_with:jackpot|numeric',
            'gameRoundClose.date' => 'required_with:gameRoundClose|date',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->errorResponse('INTERNAL_ERROR', $validator->errors()->first());
        }

        $params = $request->all();

        return $this->runLockedDealAction('gameRoundResult', $params, function (PtswDealRecord $dealRecord) use ($params) {
            // 退款前驗證原下注確實扣過款：下注在餘額檢查階段被拒時 BR 已建（bet 0）但 deal record 未 applied，
            // PT 端逾時仍會送 REFUND；若直接走 changeBalance 會由 bet 0 → −X 算出 +X 入帳（target/op_tickets#3079）。
            // 放在取錢包 DTO 之前：被拒的退款不會先留下「已受理退款」的 log。會員列鎖已在 runDealAction() 第一句取得，
            // 這裡的一般讀因此讀到現值（target/tickets#4297）。
            if (isset($params['pay']['type']) && $params['pay']['type'] == 'REFUND') {
                // PT 的 REFUND 依協定必帶 relatedTransactionCode（生產實測三站 390 筆全帶）；
                // 缺少即無法對應到哪筆下注，一律拒絕並告警，避免 multi-bet 局部扣款時放行錯筆
                $relatedTransactionCode = $params['pay']['relatedTransactionCode'] ?? null;
                if (!is_string($relatedTransactionCode) || $relatedTransactionCode === '') {
                    return $this->rejectRefund(
                        dealRecord: $dealRecord,
                        params: $params,
                        reason: 'relatedTransactionCode is required for refund',
                        logMessage: 'PTSW 退款未帶 relatedTransactionCode，一律拒絕',
                    );
                }

                if (!$this->hasAppliedBetForRefund($params['gameRoundCode'], $relatedTransactionCode)) {
                    return $this->rejectRefund(
                        dealRecord: $dealRecord,
                        params: $params,
                        reason: 'related bet not applied, nothing to refund',
                        logMessage: 'PTSW 退款拒絕：對應下注未扣款',
                    );
                }

                // 同一筆下注只能退一次：同局已有指向同一 relatedTransactionCode 的 REFUND 事件，或 refund-unsettled
                // 命令補的整局退款事件時，PT 換新 transactionCode 重送會繞過 deal record 冪等，changeBalance 由注單
                // bet 前後差再算出 +X 入帳（target/op_tickets#3089）。帶 gameRoundClose 的重送同樣在此擋下。
                if ($this->isBetAlreadyRefunded($params['gameRoundCode'], $relatedTransactionCode, $dealRecord)) {
                    return $this->rejectRefund(
                        dealRecord: $dealRecord,
                        params: $params,
                        reason: 'related bet already refunded',
                        logMessage: 'PTSW 退款拒絕：對應下注已退過款',
                    );
                }
            }

            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 取得遊戲紀錄（列鎖與 bet 路徑 firstOrNewBetRecord 對齊；named lock 只序列化回調與 refund-unsettled 命令，
            // reconcile／人工命令直接改注單不走那把鎖）。鎖序同 bet 路徑：先錢包、後注單
            $oldBetRecord = PtswBetRecord::query()
                ->where('bet_id', $params['gameRoundCode'])
                ->where('member_id', $this->member->id)
                ->lockForUpdate()
                ->first();

            if (!$oldBetRecord) {
                if (isset($params['pay']['type']) && $params['pay']['type'] == 'REFUND') {
                    return $this->rejectRefund(
                        dealRecord: $dealRecord,
                        params: $params,
                        reason: 'bet record not found',
                        logMessage: 'PTSW 退款拒絕：找不到注單',
                    );
                }

                return $this->errorResponse('INTERNAL_ERROR', 'bet record not found');
            }

            // 放在所有拒絕分支之後：REFUND 時它會把 $dealRecord->win_amount 改成正數退款額（僅供下方 win_lose 回滾用），
            // 提前呼叫會讓 rejectRefund() 的 save() 把這個髒值一起落地，破壞「退款事件 win_amount 恆為 0」的不變式
            [$betRecordStatus, $payoutTime] = $this->resolveSettlementOutcome($params, $dealRecord);

            $betRecord = clone $oldBetRecord;

            // 更新投注紀錄
            $betRecord->bet = Common::numAdd($betRecord->bet, $dealRecord->bet_amount, 4);
            $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $dealRecord->bet_amount, 4); // TODO: 有效投注額
            $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $dealRecord->win_amount, 4);
            $betRecord->jackpot = Common::numAdd($betRecord->jackpot, $dealRecord->jackpot, 4);
            $betRecord->status = $betRecordStatus;
            $betRecord->payout_time = $payoutTime;
            $betRecord->transaction_code = $dealRecord->transaction_code ?? $betRecord->transaction_code;

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            $betRecord->save();

            // 結算時再寫入 TotalBetRecord
            if ($betRecordStatus == BetRecordStatusEnum::SETTLE->value) {
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);
            }

            return $this->response($walletDto->getTotalBalance(), $dealRecord);
        }, 'settlement');
    }

    /**
     * 紅利入帳（bonus）：無 gameRoundCode，直接新建一筆已結算注單
     */
    public function transferFunds(Request $request)
    {
        $rules = [
            'transactionCode' => 'required|string',
            'amount' => 'required|numeric',
            'transactionDate' => 'required|date',
            'requestId' => 'required|string',
            'username' => 'required|string',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->errorResponse('INTERNAL_ERROR', $validator->errors()->first());
        }

        $params = $request->all();

        // transferFunds 無 gameRoundCode，不需 game round lock，僅復用交易＋deal record 冪等骨架
        return $this->runDealAction('transferFunds', $params, function (PtswDealRecord $dealRecord) use ($params) {
            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 不用取得遊戲紀錄, 這請求沒有bet_id

            // 新建遊戲紀錄
            $betRecord = PtswBetRecord::create([
                'bet_id' => null,
                'transaction_code' => $params['transactionCode'],
                'member_id' => $this->member->id,
                'username' => $this->account->username,
                'token' => $this->account->token,
                'game_code' => '',
                'bet' => 0, // 下注金额
                'valid_bet' => 0, // 有效投注額
                'win_lose' => 0, // 输赢金额, 負數
                'rolling_chips' => 0, // 禮碼
                'jackpot' => 0, // 彩池
            ]);

            $bet = '0';
            $win = Common::numDiv(abs($params['amount']), $this->vendorInfo->rate, 4);

            // 更新遊戲紀錄
            $betTime = Carbon::parse($params['transactionDate'])->toSystemTime()->toDateTimeString();
            $betRecord->bet = $bet;
            $betRecord->valid_bet = $bet;
            $betRecord->win_lose = Common::numSub($win, $bet, 4);
            $betRecord->bet_time = $betTime;
            $betRecord->payout_time = $betTime;
            $betRecord->status = BetRecordStatusEnum::SETTLE->value;

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);
            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->response($balance, $dealRecord);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 端點業務細節（bet / settlement 抽出的純業務段）
    |--------------------------------------------------------------------------
    */

    /**
     * 取得同局既有注單（鎖定）供累加；不存在則新建零值注單。
     * PTSW 允許同個 gameRoundCode 重複下注，故需允許注單更新。
     *
     * @return array{0: ?PtswBetRecord, 1: PtswBetRecord} [原注單（新局為 null）, 可寫入的注單]
     */
    protected function firstOrNewBetRecord(array $params, string $gameCode): array
    {
        // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
        // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
        // 同局累加不靠這把鎖：外層 GameRoundLock 已序列化同一（gameRoundCode, username）的回調，
        // 交易內又先鎖了會員列；跨局撞號由 bet_id UNIQUE 擋下。
        $oldBetRecord = PtswBetRecord::query()
            ->where('bet_id', $params['gameRoundCode'])
            ->where('member_id', $this->member->id)
            ->first();

        if ($oldBetRecord) {
            return [$oldBetRecord, clone $oldBetRecord];
        }

        $betRecord = PtswBetRecord::create([
            'bet_id' => $params['gameRoundCode'],
            'transaction_code' => $params['transactionCode'],
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'token' => $this->account->token,
            'game_code' => $gameCode,
            'bet' => 0, // 下注金额
            'valid_bet' => 0, // 有效投注額
            'win_lose' => 0, // 输赢金额, 負數
            'rolling_chips' => 0, // 禮碼
            'jackpot' => 0,
            'bet_time' => null, // 三方請求時間 UTC
            'status' => BetRecordStatusEnum::IN_PROGRESS->value,
        ]);

        return [null, $betRecord];
    }

    /**
     * 同一 bet_id + transaction_code 已有 applied 紀錄＝交易已處理過（requestId 之外的第二層冪等）。
     */
    protected function hasAppliedDealTransaction(PtswDealRecord $dealRecord): bool
    {
        return $this->dealRecordModel::query()
            ->where('bet_id', $dealRecord->bet_id)
            ->where('transaction_code', $dealRecord->transaction_code)
            ->whereNotNull('applied_at')
            ->exists();
    }

    /**
     * REFUND 對應的下注（同局、同帳號、pay.relatedTransactionCode 指向的 bet 事件）是否真的扣過款：
     * deal record type=bet 且 applied_at 非 NULL。
     */
    protected function hasAppliedBetForRefund(string $gameRoundCode, string $relatedTransactionCode): bool
    {
        return $this->dealRecordModel::query()
            ->where('bet_id', $gameRoundCode)
            ->where('username', $this->account->username)
            ->where('type', 'bet')
            ->where('transaction_code', $relatedTransactionCode)
            ->whereNotNull('applied_at')
            ->exists();
    }

    /**
     * 這筆下注是否已退過款：同局、同帳號的 gameRoundResult 事件（排除本次剛建立的 deal record）中，任一符合即是——
     * (1) pay.type=REFUND 且 pay.relatedTransactionCode 指向同一筆下注（PT 回調寫入的退款事件）；
     * (2) refund-unsettled 命令補的退款事件（raw 帶 _recovery）：命令以整局 BR.bet 退款、只補一筆事件，其
     *     relatedTransactionCode 只指到一筆下注，multi-bet 局其餘下注的 tc 在 (1) 比不中，故此類事件視為整局已退。
     * gameRoundResult 事件不寫 applied_at、raw 為 TEXT 存 JSON，故先以索引欄縮到同局候選（一局至多數筆）再以 cast 屬性判斷；
     * relatedTransactionCode 比對不分大小寫，對齊 transaction_code 欄 utf8mb4_unicode_ci 的冪等語意。
     * 不做「整局有任何退款即拒」：PTSW live 允許同局多筆下注，PT 取消整局時會對每筆下注各送一次 REFUND。
     * 被拒的退款事件（raw 帶 _rejected，見 rejectRefund）沒動過錢包與注單，不算已退。
     * 人工撤回命令退款時必須一併刪掉補的 deal record（docs/reconcile/vendors/PTSW.md），否則該局 PT 後續合法 REFUND 會被此閘永久拒絕。
     */
    protected function isBetAlreadyRefunded(string $gameRoundCode, string $relatedTransactionCode, PtswDealRecord $currentDealRecord): bool
    {
        return $this->dealRecordModel::query()
            ->where('bet_id', $gameRoundCode)
            ->where('username', $this->account->username)
            ->where('type', 'gameRoundResult')
            ->whereKeyNot($currentDealRecord->getKey())
            ->get()
            ->contains(static function (PtswDealRecord $event) use ($relatedTransactionCode): bool {
                $pay = $event->raw['pay'] ?? null;
                if (!is_array($pay) || ($pay['type'] ?? null) !== 'REFUND' || isset($event->raw['_rejected'])) {
                    return false;
                }

                if (isset($event->raw['_recovery'])) {
                    return true;
                }

                return is_string($pay['relatedTransactionCode'] ?? null)
                    && strcasecmp($pay['relatedTransactionCode'], $relatedTransactionCode) === 0;
            });
    }

    /**
     * 拒絕退款：先在本次 deal record 的 raw 補 `_rejected`（錯誤碼＋原因）再回 ERR_NO_BET。
     * 被拒的請求在 runDealAction 的交易內已由 createDealRecord 落地且不回滾（同 requestId／transactionCode 重送要走冪等早退），
     * 留下的事件與生效的退款沖銷事件同形（type=gameRoundResult、bet_amount<0、applied_at=NULL），標記是唯一的區分依據：
     * isBetAlreadyRefunded() 不把它當已退、playtech:reconcile-bet-accumulation 不讓它沖掉 expected_bet。
     * 位置與寫法比照 refund-unsettled 命令補的退款事件所帶的 `_recovery`。
     */
    protected function rejectRefund(PtswDealRecord $dealRecord, array $params, string $reason, string $logMessage): JsonResponse
    {
        Log::warning($logMessage . ' ' . $params['gameRoundCode'], Common::maskCredentials($params));

        $dealRecord->raw = array_merge($dealRecord->raw ?? [], ['_rejected' => ['code' => 'ERR_NO_BET', 'reason' => $reason]]);
        $dealRecord->save();

        return $this->errorResponse('ERR_NO_BET', $reason);
    }

    /**
     * 解析結算請求的注單狀態與派彩時間。
     * REFUND 時同步把 $dealRecord->win_amount 改寫為正數退款額（僅改屬性、未 save），
     * 供後續 win_lose 累加時把輸贏回滾回未投注。
     *
     * @return array{0: int, 1: ?string} [betRecordStatus, payoutTime]
     */
    protected function resolveSettlementOutcome(array $params, PtswDealRecord $dealRecord): array
    {
        $betRecordStatus = BetRecordStatusEnum::IN_PROGRESS->value;
        $payoutTime = null;

        // ! 退款
        if (isset($params['pay']) && $params['pay']['type'] == 'REFUND') {
            // 退款金額 (負數轉正)；bet_amount 為 DECIMAL string，需走 bcmath 避免大金額 float 精度丟失
            $dealRecord->win_amount = Common::numCompare($dealRecord->bet_amount, '0') < 0
                ? Common::numMul($dealRecord->bet_amount, '-1', 4)
                : $dealRecord->bet_amount;
            $betRecordStatus = BetRecordStatusEnum::REFUND->value;
            $payoutTime = Carbon::parse($params['pay']['transactionDate'])->toSystemTime()->toDateTimeString();

            Log::warning('PTSW Refund: ' . $params['gameRoundCode'], Common::maskCredentials($params));
        }

        if (isset($params['gameRoundClose'])) {
            // 遊戲回合關閉, 強制更新注單狀態
            $betRecordStatus = BetRecordStatusEnum::SETTLE->value;
            $payoutTime = Carbon::parse($params['gameRoundClose']['date'])->toSystemTime()->toDateTimeString();
        }

        return [$betRecordStatus, $payoutTime];
    }

    /*
    |--------------------------------------------------------------------------
    | 共用骨架：鎖・交易・冪等
    |--------------------------------------------------------------------------
    | 呼叫鏈：runLockedDealAction → GameRoundLock::synchronize → runDealAction → $handler
    | transferFunds 無 gameRoundCode，跳過鎖、直接走 runDealAction。
    */

    /**
     * bet / settlement 共用骨架：以 game round lock（與 refund-unsettled 補帳命令共用同一把）序列化同局併發後，走 runDealAction。
     * 只承接取鎖失敗（錯誤碼依 action 分流，見下）；其他 ApiException 維持冒至 callAction → INTERNAL_ERROR。
     */
    protected function runLockedDealAction(string $action, array $params, Closure $handler, ?string $logLabel = null): JsonResponse
    {
        $lockKey = GameRoundLock::key($params['gameRoundCode'], $this->account->username);

        try {
            return GameRoundLock::synchronize($lockKey, fn () => $this->runDealAction($action, $params, $handler, $logLabel));
        } catch (LockTimeoutException $e) {
            $errorCode = $this->lockTimeoutErrorCode($action);

            Log::warning('PTSW 同局鎖逾時 ' . $params['gameRoundCode'], [
                'action' => $action,
                'error_code' => $errorCode,
                'timeout_seconds' => GameRoundLock::TIMEOUT_SECONDS,
            ]);

            return $this->errorResponse($errorCode, $e->getMessage());
        }
    }

    /**
     * 取鎖逾時要回哪個錯誤碼——關鍵在於「PT 收到之後還會不會再送這一筆」。
     *
     * `gameRoundResult`（結算）回可重試的 `INTERNAL_ERROR`：api.md「9. 錯誤碼」把
     * `ERR_TRANSACTION_DECLINED` 列為不可重試，PT 收到就不再送這筆結算，注單會永遠停在進行中
     * （`status=1`）、TBR 不建，而下注款早已扣走（target/op_tickets#3101，形狀同 target/op_tickets#3083）。
     * 同節列的可重試碼中，`INTERNAL_ERROR` 是唯一 `bet` 與 `gameRoundResult` 皆適用的，PT 會依
     * 「10. 重試與冪等性」的退避（1、2、4、8… 分鐘）重送，重送由既有的 deal record 冪等接住。
     *
     * `bet`（投注）維持 `ERR_TRANSACTION_DECLINED`：撞鎖代表同局另一筆請求正在處理，這一筆本來就
     * 不該成立，讓 PT 視為投注失敗才是對的——重試反而可能變成重複下注。
     *
     * 用 `match` 逐一列舉而不是三元式：日後有第三種 action 接進 runLockedDealAction() 時，三元式的
     * fallthrough 會讓它默默拿到**不可重試**的碼——正是本單要修的那種永久損失。這裡把預設值放在
     * 可重試的安全側，未列舉的 action 寧可讓三方重送，也不要讓它以為「別再送了」。
     */
    protected function lockTimeoutErrorCode(string $action): string
    {
        return match ($action) {
            'bet' => 'ERR_TRANSACTION_DECLINED',
            default => 'INTERNAL_ERROR',
        };
    }

    /**
     * deal action 共用骨架（內含冪等重試早退）：於交易中建立 deal record 後交給 $handler；
     * 相同 requestId 重試（wasRecentlyCreated = false）直接回傳現有餘額，不進 $handler。
     *
     * @param  Closure(PtswDealRecord): JsonResponse  $handler  純業務段，收到的必為本次新建的 deal record
     *
     * @throws \Throwable
     */
    protected function runDealAction(string $action, array $params, Closure $handler, ?string $logLabel = null): JsonResponse
    {
        return DB::transaction(function () use ($action, $params, $handler, $logLabel) {
            // 交易內第一個 SQL 必須是鎖會員列：下面的 deal record 冪等查詢與 refund 前置檢查都是一般讀，
            // 若跑在鎖前，RR 快照就定在等鎖之前，拿到鎖後讀注單、現金紀錄仍是舊照片（target/tickets#4297）。
            // 鎖主鍵列不取 gap lock；$handler 內的 getMemberLockedWallets() 重鎖同一列，不增加等待。
            Member::query()->whereKey($this->member->id)->lockForUpdate()->first();

            // 紀錄請求資料
            try {
                $dealRecord = $this->createDealRecord($action, $params);
            } catch (ApiException $e) {
                Log::error('PTSW_createDealRecord_' . ($logLabel ?? $action), [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                return $this->errorResponseFromApiException($e);
            }

            if (!$dealRecord->wasRecentlyCreated) {
                $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

                return $this->response($balance, $dealRecord);
            }

            return $handler($dealRecord);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Deal Record：請求紀錄與冪等判定
    |--------------------------------------------------------------------------
    */

    protected function createDealRecord(string $action, array $params): PtswDealRecord
    {
        [$betAmount, $winAmount, $jackpot, $transactionCode, $resolvedAction] = $this->resolveDealAmounts($action, $params);

        if ($resolvedAction !== null) {
            $params['action'] = $resolvedAction;
        }

        // 底線前綴是我方獨占的標記命名空間：_rejected 由 rejectRefund() 寫、_recovery 由 refund-unsettled 命令寫。
        // 三方 payload 夾帶時剝掉，否則生效的退款事件會被 isBetAlreadyRefunded() 與補帳 SQL 當成被拒（fail-open），
        // 或被當成命令的整局退款而永久擋掉該局後續合法 REFUND
        unset($params['_rejected'], $params['_recovery']);

        $existingRecord = $this->findExistingDealRecord($params, $transactionCode);
        if ($existingRecord) {
            return $existingRecord;
        }

        try {
            return $this->insertDealRecord([
                'request_id' => $params['requestId'],
                'username' => $this->account->username,
                'token' => $this->account->token,
                'bet_id' => $params['gameRoundCode'] ?? null,
                'transaction_code' => $transactionCode ?: null,
                'type' => $action,
                'bet_amount' => $betAmount,
                'win_amount' => $winAmount,
                'jackpot' => $jackpot,
                'external_transaction_code' => Str::uuid()->toString(),
                'external_transaction_date' => Carbon::now()->format('Y-m-d H:i:s.v'),
                'raw' => $params, // 原始數據
            ]);
        } catch (QueryException $e) {
            // 1062 duplicate：併發重播（另一 tx 已插入同 bet_id+tc）→ re-select 既有返回，非 rollback
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $replay = $this->findExistingDealRecord($params, $transactionCode);
                if ($replay) {
                    return $replay;
                }
            }

            throw $e;
        }
    }

    protected function insertDealRecord(array $attributes): PtswDealRecord
    {
        return $this->dealRecordModel::create($attributes);
    }

    /**
     * 依 action 推導 bet/win/jackpot/transactionCode 與寫入 raw 的 action 標記。
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: ?string}
     */
    protected function resolveDealAmounts(string $action, array $params): array
    {
        // 下注／bonus：金額換算匯率成我方幣值；結算另走 resolveSettlementAmounts
        [$betAmount, $winAmount, $jackpot, $transactionCode, $resolvedAction] = match ($action) {
            'gameRoundResult' => $this->resolveSettlementAmounts($params),
            'bet' => [Common::numDiv(abs($params['amount'] ?? 0), $this->vendorInfo->rate, 4), '0', '0', $params['transactionCode'], null],
            'transferFunds' => ['0', Common::numDiv(abs($params['amount'] ?? 0), $this->vendorInfo->rate, 4), '0', $params['transactionCode'], null],
            default => ['0', '0', '0', '', null],
        };

        // 遊戲回合關閉是獨立的 modifier，會覆蓋上方任何 resolvedAction
        if (isset($params['gameRoundClose'])) {
            $resolvedAction = 'close';
        }

        return [$betAmount, $winAmount, $jackpot, $transactionCode, $resolvedAction];
    }

    /**
     * 結算（gameRoundResult）金額推導：派彩由 $params['pay']['amount'] 取得，
     * 彩池獎金由 $params['jackpot']['winAmount'] 取得；無 pay 即為輸（未中獎不會有 transactionCode）。
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    protected function resolveSettlementAmounts(array $params): array
    {
        $jackpot = isset($params['jackpot'])
            ? Common::numDiv($params['jackpot']['winAmount'], $this->vendorInfo->rate, 4)
            : '0';

        if (!isset($params['pay'])) {
            return ['0', '0', $jackpot, '', 'lose'];
        }

        // win 已包含在派彩金額中，需扣除 jackpot 取得純贏額
        $winAmount = Common::numSub(
            Common::numDiv($params['pay']['amount'], $this->vendorInfo->rate, 4),
            $jackpot,
            4
        );

        // 退款：原本派彩金額轉為負的 bet_amount，win_amount 歸零
        if ($params['pay']['type'] == 'REFUND') {
            $betAmount = Common::numCompare($winAmount, '0') > 0
                ? Common::numMul($winAmount, '-1', 4)
                : $winAmount;

            return [$betAmount, '0', $jackpot, $params['pay']['transactionCode'], 'refund'];
        }

        return ['0', $winAmount, $jackpot, $params['pay']['transactionCode'], 'win'];
    }

    /**
     * 依 requestId / bet_id / transactionCode 組合查找既有 deal record，命中即視為冪等重試。
     */
    protected function findExistingDealRecord(array $params, string $transactionCode): ?PtswDealRecord
    {
        $requestId = $params['requestId'];
        $gameRoundCode = $params['gameRoundCode'] ?? null;

        // requestId + bet_id 冪等性（一般 bet/settlement case）
        if ($gameRoundCode !== null) {
            $existing = PtswDealRecord::query()
                ->where('request_id', $requestId)
                ->where('bet_id', $gameRoundCode)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // transferFunds 無 gameRoundCode，改以 requestId + transactionCode 作冪等
        if ($gameRoundCode === null && !empty($transactionCode)) {
            $existing = PtswDealRecord::query()
                ->where('request_id', $requestId)
                ->where('transaction_code', $transactionCode)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // bet_id + transactionCode 唯一性，避免重複結算/退款
        if (!empty($transactionCode)) {
            $existing = PtswDealRecord::query()
                ->where('bet_id', $gameRoundCode)
                ->where('transaction_code', $transactionCode)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | 回應組裝
    |--------------------------------------------------------------------------
    */

    /**
     * 成功回傳
     *
     * @param  string  $balance  本地錢包餘額
     * @return JsonResponse
     */
    protected function response(string $balance, ?PtswDealRecord $dealRecord = null)
    {
        $realBalance = Common::numMul($balance, $this->vendorInfo->rate, 2);
        $realBalance = round((float) $realBalance, 2); // 四捨五入到小數點後兩位

        $return = [
            'requestId' => $this->requestId,
            'balance' => [
                'real' => $realBalance,
                'timestamp' => Carbon::now()->format('Y-m-d H:i:s.v'),
            ],
        ];

        if ($dealRecord) {
            $return += [
                'externalTransactionCode' => $dealRecord->external_transaction_code,
                'externalTransactionDate' => $dealRecord->external_transaction_date,
            ];
        }

        return response()->json($return);
    }

    /**
     * 錯誤回傳
     *
     * @param  string  $errorCode  錯誤代碼, 需要符合三方格式
     * @param  string  $errorMessage  錯誤訊息, 可以自訂
     * @return JsonResponse
     */
    protected function errorResponse(string $errorCode, string $errorMessage)
    {
        $content = [
            'requestId' => $this->requestId,
            'error' => [
                'code' => $errorCode,
                'description' => $errorMessage,
            ],
        ];

        Log::error('PlayTech Error: ' . $this->requestId, $content['error']);

        return response()->json($content);
    }

    /**
     * ApiException message 慣例為「錯誤碼|訊息」，拆解後轉為三方錯誤回應。
     */
    protected function errorResponseFromApiException(ApiException $e): JsonResponse
    {
        $msgArr = explode('|', $e->getMessage());

        return $this->errorResponse($msgArr[0], $msgArr[1] ?? '');
    }
}
