<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\General\ContextKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Requests\External\Astar\AuthenticateRequest;
use App\Http\Requests\External\Astar\DetailtokenRequest;
use App\Http\Requests\External\Astar\RefundRequest;
use App\Http\Requests\External\Astar\RollinRequest;
use App\Http\Requests\External\Astar\RolloutRequest;
use App\Http\Requests\External\Astar\RoundcheckRequest;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Astar\Datas\AstarVendorInfoDetail;
use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use App\Vendors\Astar\Enums\ErrorCodeEnum;
use App\Vendors\Models\AstarAccount;
use App\Vendors\Models\AstarBetRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-astar
 */
class AstarController extends BaseController
{
    protected int $vendorId = VendorIdEnum::ASTAR->value;

    protected string $accountClass = AstarAccount::class;

    protected string $betRecordClass = AstarBetRecord::class;

    protected string $actionPrefix = 'Astar_SW_request_';

    protected string $vendorInfoOperatorField = 'GAMEHALL';

    private AstarVendorInfoDetail $infoDetail;

    private array $requestInfos = [];

    /**
     * 前置處理（同 ClotPlayController）：請求 log、IP 白名單，以及帳號／會員／商戶一律在**交易外**解出，
     * 讓 rollout／rollin／refund 進 DB::transaction 後第一個 SQL 就是鎖會員列。
     * 在交易內先做一般讀會把 REPEATABLE READ 的快照定在等鎖之前，拿到鎖後讀注單、
     * 現金紀錄仍是舊照片（target/tickets#4294、target/op_tickets#3075）。
     *
     * rollin／refund 依規格要從注單反查帳號，這裡只做不加鎖的定位；注單本體進交易後
     * 於會員鎖之後再以 lockForUpdate 重取，已結算者照舊回 ORDER_NOT_FOUND 維持冪等。
     */
    public function callAction($method, $parameters)
    {
        $request = request();
        $params = $request->all();

        $this->logRequest($request, $this->actionPrefix . basename(url()->current()));
        $this->requestInfos = [
            'headers' => Common::flattenHeaders($request->header()),
            'params' => Common::maskCredentials($params),
        ];

        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorLogAndResponse(
                ErrorCodeEnum::AUTHORIZATION_INVALID->value,
                'IP' . Common::getIp() . 'not allowed'
            );
        }

        try {
            $gamehall = (string) ($parameters['gamehall'] ?? '');

            switch ($method) {
                case 'authenticate':
                case 'rollout':
                case 'detailtoken':
                    $this->loadAccountAndVerify($gamehall);
                    break;
                case 'balance':
                    $this->loadAccountAndVerify($gamehall, (string) ($parameters['id'] ?? ''));
                    break;
                case 'rollin':
                    $round = (string) ($params['round'] ?? '');
                    $this->validateMtCode((string) ($params['mtcode'] ?? ''), 'rollin', $round);
                    $this->loadSettlementContext($gamehall, [$round], (string) ($params['id'] ?? ''), $params['gametoken'] ?? null);
                    break;
                case 'refund':
                    $this->loadSettlementContext(
                        $gamehall,
                        $this->resolveRefundRoundCandidates($params),
                        (string) ($params['id'] ?? ''),
                        $params['gametoken'] ?? null
                    );
                    break;
            }
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return parent::callAction($method, $parameters);
    }

    /**
     * 驗證遊戲帳號 GameToken
     * /gamepool/{gamehall}/player/auth
     */
    public function authenticate(AuthenticateRequest $request, string $gamehall): JsonResponse
    {
        try {
            if ($this->account->status == 0) {
                throw new Exception('Player not found or invalid', ErrorCodeEnum::PLAYER_NOT_FOUND->value);
            }

            $responseData = $this->getResponseArray(
                data: [
                    'account' => $this->account->username,
                    'currency' => $this->infoDetail->CURRENCY,
                    'id' => $this->account->username,
                    'betthreshold' => ['maximum' => 0, 'minimum' => 0],
                ]
            );
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return response()->json($responseData);
    }

    /**
     * 查餘額
     * /gamepool/{gamehall}/player/balance/{id}
     */
    public function balance(Request $request, string $gamehall, string $id): JsonResponse
    {
        try {
            if ($this->account->status == 0) {
                throw new Exception('Player not found or invalid', ErrorCodeEnum::PLAYER_NOT_FOUND->value);
            }

            if ($this->member->status !== 1 || $this->member->sys_lock === 1) {
                return $this->errorLogAndResponse(ErrorCodeEnum::PLAYER_IS_FROZEN->value, 'Member is frozen');
            }

            $ableToBet = BetHelper::isMemberFlowLimitAllowed($this->member, VendorIdEnum::ASTAR->value);
            if (!$ableToBet) {
                return $this->errorLogAndResponse(ErrorCodeEnum::PLAYER_IS_FROZEN->value, 'Account could not bet yet');
            }

            $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

            $responseData = $this->getResponseArray(data: [
                'balance' => $balance,
                'currency' => $this->infoDetail->CURRENCY,
            ]);
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return response()->json($responseData);
    }

    /**
     * 登出
     * /gamepool/{gamehall}/player/logout
     */
    public function logout(Request $request, string $gamehall): JsonResponse
    {
        try {
            AstarAccount::whereNotNull('session_token')
                ->where('session_token', request()->input('gametoken'))
                ->update(['session_token' => '']);

            $responseData = $this->getResponseArray();
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return response()->json($responseData);
    }

    /**
     * 扣款
     * /gamepool/{gamehall}/table/rollout
     */
    public function rollout(RolloutRequest $request, string $gamehall): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                // 進交易第一個 SQL：鎖會員列（帳號／商戶已於 callAction 在交易外解出）
                $this->member = Member::lockForUpdate()->where('id', $this->member->id)->first();

                $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

                if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $request->input('gamecode'))) {
                    throw new Exception('Account could not bet yet', ErrorCodeEnum::PLAYER_IS_FROZEN->value);
                }

                $gameExists = $this->getGameByCode($request->input('gamecode')) !== null;
                if (!$gameExists) {
                    throw new Exception('Game not found', ErrorCodeEnum::GAME_NOT_FOUND_OR_DISABLED->value);
                }

                $round = $request->input('round');
                $this->validateMtCode($request->input('mtcode'), 'rollout', $round);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare(Common::numSub($walletDto->getTotalBalance(), $amount), '0') < 0) {
                    throw new Exception('Wallet balance not enough', ErrorCodeEnum::INSUFFICIENT_BALANCE->value);
                }

                $betRecord = new AstarBetRecord([
                    'bet_id' => $round,
                    'vendor_username' => $request->input('id'),
                    'game_code' => $request->input('gamecode'),
                    'bet' => $amount,
                    'valid_bet' => $amount,
                    'win_lose' => Common::numMul($amount, -1),
                    'rolling_chip' => 0,
                    'status' => BetRecordStatusEnum::ROLLOUT,
                    'session_token' => $request->input('gametoken'),
                    'bet_time' => Carbon::parse($request->input('datetime'))->toSystemTime()->toDateTimeString(),
                    'payout_time' => Carbon::parse($request->input('datetime'))->toSystemTime()->toDateTimeString(),
                    'info' => json_encode([$request->all()]),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

                return response()->json($this->getResponseArray(data: ['balance' => $balance]));
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorLogAndResponse(ErrorCodeEnum::ROLLOUT_EXISTS->value, 'Bet record duplicated');
            }
            throw $e;
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    /**
     * 入款
     * /gamepool/{gamehall}/table/rollin
     */
    public function rollin(RollinRequest $request, string $gamehall): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                // 進交易第一個 SQL：鎖會員列；注單於鎖後重取，避免拿到等鎖前快照裡的舊狀態
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $betRecord = $this->findRolloutRecord([(string) $request->input('round')], lock: true);

                // 入帳一律以 amount 為準：輸贏 = amount − rollout 帶入金額，changeBalance 據此只補回 amount
                $rolloutAmount = (string) $betRecord->bet;
                $settleAmount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);
                $winLoseAmount = Common::numSub($settleAmount, $rolloutAmount, 4);

                $isBoardCard = $this->isBoardCardTable((string) $request->input('tablename'));
                if ($isBoardCard) {
                    // 棋牌的 win 是用戶目前餘額、validbet 是負的帶入金額，兩者都不能當輸贏與有效投注
                    $validBetAmount = Common::numCompare($winLoseAmount, '0') < 0
                        ? Common::numMul($winLoseAmount, -1, 4)
                        : $winLoseAmount;
                } else {
                    $validBetAmount = Common::numDiv($request->input('validbet'), $this->vendorInfo->rate);
                    $this->warnIfSettleAmountMismatch($request, $rolloutAmount, $settleAmount);
                }

                $beforeBetRecord = clone $betRecord;
                $betRecord->valid_bet = $validBetAmount;
                $betRecord->win_lose = $winLoseAmount;
                $betRecord->status = BetRecordStatusEnum::ROLLIN->value;
                $betRecord->payout_time = Carbon::parse($request->input('datetime'))->toSystemTime()->toDateTimeString();
                $betRecord->info = $this->appendParamsToInfo($betRecord->info, $request->all());

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

                // 棋牌帶入金額不等於押注，投注改記 |輸贏|；須在 changeBalance 之後改，bet 變動才不會觸發禮碼扣退而影響入帳
                if ($isBoardCard) {
                    $betRecord->bet = $validBetAmount;
                    $betRecord->save();
                }

                (new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet))->create($betRecord, $transactionLogId);

                $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

                return response()->json($this->getResponseArray(data: ['balance' => $balance]));
            });
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    /**
     * 驗證細單 Token 正確性並取回相關細單資訊
     * /gamepool/{gamehall}/game/detailtoken
     */
    public function detailtoken(DetailtokenRequest $request, string $gamehall): JsonResponse
    {
        try {
            $betRecord = AstarBetRecord::where('session_token', $request->input('token'))
                ->where(fn ($q) => $q
                    ->where('vendor_username', $this->account->username)
                    ->orWhere('vendor_username', $this->account->vendor_username)
                )
                ->select('bet_id')
                ->first();

            $responseData = $this->getResponseArray(data: [
                'roundid' => $betRecord->bet_id,
                'account' => $this->account->username,
            ]);
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return response()->json($responseData);
    }

    /**
     * 查詢未完成注單
     * /gamepool/{gamehall}/game/roundcheck
     */
    public function roundcheck(RoundcheckRequest $request, string $gamehall): JsonResponse
    {
        try {
            $start = Carbon::parse($request->input('fromdate'))->toSystemTime()->toDateTimeString();
            $end = Carbon::parse($request->input('todate'))->toSystemTime()->toDateTimeString();

            $betIds = AstarBetRecord::where('status', BetRecordStatusEnum::ROLLOUT)
                ->whereBetween('bet_time', [$start, $end])
                ->where('game_code', 'LIKE', $gamehall . '%')
                ->pluck('bet_id')
                ->toArray();

            $responseData = $this->getResponseArray(data: $betIds);
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }

        return response()->json($responseData);
    }

    /**
     * 下注取消
     * /gamepool/{gamehall}/game/bet/refund
     */
    public function refund(RefundRequest $request, string $gamehall): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $params = $request->all();

                // 進交易第一個 SQL：鎖會員列；注單於鎖後重取，避免拿到等鎖前快照裡的舊狀態
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $oldBetRecord = $this->findRolloutRecord($this->resolveRefundRoundCandidates($params), lock: true);

                $betRecord = clone $oldBetRecord;
                $betRecord->bet = 0;
                $betRecord->valid_bet = 0;
                $betRecord->win_lose = 0;
                $betRecord->payout_time = now()->toDateTimeString();
                $betRecord->status = BetRecordStatusEnum::REFUND->value;
                $betRecord->info = $this->appendParamsToInfo($betRecord->info, $params);
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord, autoSave: false);
                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

                return response()->json($this->getResponseArray(data: ['balance' => $balance]));
            });
        } catch (Exception $e) {
            return $this->errorLogAndResponse($e->getCode(), $e->getMessage());
        }
    }

    private function loadAccountAndVerify(string $gamehall, string $username = ''): void
    {
        $account = AstarAccount::where('member_id', '>', 0)
            ->when($username, fn ($q) => $q->where('username', $username))
            ->when(!$username, fn ($q) => $q
                ->whereNotNull('session_token')
                ->where('session_token', request()->input('gametoken'))
            )
            ->first();

        if (!is_object($account)) {
            throw new Exception('Player not found or invalid', ErrorCodeEnum::PLAYER_NOT_FOUND->value);
        }

        $this->verifyVendorInfoAndMember($account, $gamehall);
    }

    /**
     * 以 URL 的 gamehall 找出啟用中的 Astar 商戶並比對 CS_TOKEN；在查注單之前執行，
     * 讓未授權請求拿不到「round 是否存在」的差異回應，也不會先取到注單列鎖。
     */
    private function authorizeGamehall(string $gamehall): VendorInfo
    {
        $csToken = [REDACTED]'Authorization', null);

        $vendorInfos = VendorInfo::where('vendor_id', VendorIdEnum::ASTAR->value)
            ->where('status', 1)
            ->orderBy('id')
            ->get();

        foreach ($vendorInfos as $vendorInfo) {
            $detail = AstarVendorInfoDetail::from($vendorInfo->info);
            if ($detail->GAMEHALL === strtoupper($gamehall) && $detail->CS_TOKEN === $csToken) {
                return $vendorInfo;
            }
        }

        throw new Exception('Game hall or CS token mismatch', ErrorCodeEnum::TOKEN_INVALID->value);
    }

    /**
     * 結算／退款的交易外前置：先驗商戶授權（GAMEHALL + CS_TOKEN）再碰注單，讓未授權請求
     * 拿不到「round 是否存在」的差異回應；再由注單反查帳號並掛到 controller 狀態。
     *
     * 結算以「回合 → 注單 → 帳號」定位，不拿帳號現行 session_token 比對 gametoken：Astar 重送
     * rollin 帶的是下注當時的 gametoken，而 Driver::getGameUrl() 每次進遊戲都會重發 session_token，
     * 舊做法會讓已扣款的 rollout 永遠卡在 ROLLOUT（op_tickets#3068）。refund 依規格只帶 mtcode，
     * 沒有 id 也沒有 gametoken，帳號只能由注單反查。
     *
     * @param  list<string>  $roundCandidates
     */
    private function loadSettlementContext(string $gamehall, array $roundCandidates, string $playerId, ?string $gametoken): void
    {
        $vendorInfo = $this->authorizeGamehall($gamehall);
        $betRecord = $this->findRolloutRecord($roundCandidates);
        $this->loadAccountForSettlement($vendorInfo, $gamehall, $betRecord, $playerId, $gametoken);
    }

    /**
     * 取得尚未結算（ROLLOUT）的注單；依候選 round 順序逐一嘗試，
     * 已結算／已退款／不存在一律視為 ORDER_NOT_FOUND，維持冪等。
     *
     * $lock=false 供交易外定位帳號用（一般讀）；交易內拿到會員鎖之後必須以 $lock=true 重取，
     * 鎖定讀讀到的是現值，不受等鎖前快照影響。
     *
     * @param  list<string>  $roundCandidates
     */
    private function findRolloutRecord(array $roundCandidates, bool $lock = false): AstarBetRecord
    {
        foreach ($roundCandidates as $round) {
            $betRecord = AstarBetRecord::query()
                ->when($lock, fn ($q) => $q->lockForUpdate())
                ->where('bet_id', $round)
                ->where('status', BetRecordStatusEnum::ROLLOUT)
                ->first();

            if (is_object($betRecord)) {
                return $betRecord;
            }
        }

        $round = $roundCandidates[0] ?? '';

        throw new Exception(
            "Bet id $round not found or already has result",
            ErrorCodeEnum::ORDER_NOT_FOUND->value
        );
    }

    /**
     * 結算／退款用的帳號定位：由注單的 vendor_username 反查同商戶的帳號。
     * - 注單的 vendor_username 有兩種格式，所以兩欄都要能命中：Astar 回調改由本系統處理之前，
     *   舊系統 API_External 寫入的注單存帳號的 vendor_username（帶 TP 前綴）；本系統的 rollout
     *   存請求的 id（= 帳號 username）。
     * - 請求有帶 id 時必須是該帳號的 username 或 vendor_username，防止跨玩家沖帳。
     * - 請求有帶 gametoken 時，須等於注單當時的 session_token 或帳號現行 session_token（Astar 重送帶舊 token）；
     *   refund 依規格不帶 gametoken，則不比對。
     */
    private function loadAccountForSettlement(
        VendorInfo $vendorInfo,
        string $gamehall,
        AstarBetRecord $betRecord,
        string $playerId,
        ?string $gametoken
    ): void {
        $account = AstarAccount::where('member_id', '>', 0)
            ->where('vendor_info_id', $vendorInfo->id)
            ->where(fn ($q) => $q
                ->where('username', $betRecord->vendor_username)
                ->orWhere('vendor_username', $betRecord->vendor_username)
            )
            ->orderBy('id')
            ->first();

        if (!is_object($account)) {
            throw new Exception('Player not found or invalid', ErrorCodeEnum::PLAYER_NOT_FOUND->value);
        }

        if ($playerId !== '' && !in_array($playerId, [$account->username, $account->vendor_username], true)) {
            throw new Exception('Player not found or invalid', ErrorCodeEnum::PLAYER_NOT_FOUND->value);
        }

        if ($gametoken !== null && $gametoken !== '' &&
            !in_array($gametoken, [$betRecord->session_token, $account->session_token], true)
        ) {
            throw new Exception('Invalid gametoken', ErrorCodeEnum::INVALID_GAMETOKEN->value);
        }

        $this->verifyVendorInfoAndMember($account, $gamehall);
    }

    /**
     * refund 的 round 由 mtcode 推回，回傳依優先序排列的候選：
     * 先把整串 mtcode 當 round 精確比對，查無且為 {env}-{action}-{round} 三段式時再取第三段；
     * 請求若另帶 round 則必須是候選之一。
     *
     * @return list<string>
     */
    private function resolveRefundRoundCandidates(array $params): array
    {
        $mtCode = (string) ($params['mtcode'] ?? '');
        if ($mtCode === '') {
            throw new Exception('Invalid mtCode format', ErrorCodeEnum::BAD_PARAMETERS->value);
        }

        $candidates = [$mtCode];
        $parts = explode('-', $mtCode);
        if (count($parts) === 3 && $parts[2] !== '') {
            $candidates[] = $parts[2];
        }

        if (isset($params['round']) && !in_array((string) $params['round'], $candidates, true)) {
            throw new Exception('Invalid mtCode format: round mismatch', ErrorCodeEnum::BAD_PARAMETERS->value);
        }

        return $candidates;
    }

    /**
     * 共用的商戶／會員驗證：vendorInfo 啟用、GAMEHALL 與 CS_TOKEN 相符、會員存在，並掛到 controller 狀態。
     */
    private function verifyVendorInfoAndMember(AstarAccount $account, string $gamehall): void
    {
        $account->load([
            'vendorInfo' => fn ($q) => $q
                ->where('status', 1)
                ->where('vendor_id', VendorIdEnum::ASTAR->value),
        ]);

        $vendorInfo = $account->vendorInfo;
        if (!is_object($vendorInfo)) {
            throw new Exception('Vendor info not found', ErrorCodeEnum::AUTHORIZATION_INVALID->value);
        }

        $this->infoDetail = AstarVendorInfoDetail::from($vendorInfo->info);
        if ($this->infoDetail->GAMEHALL !== strtoupper($gamehall) ||
            $this->infoDetail->CS_TOKEN !== request()->header('Authorization', null)
        ) {
            throw new Exception('Game hall or CS token mismatch', ErrorCodeEnum::TOKEN_INVALID->value);
        }

        if (!is_object($account->member)) {
            throw new Exception('Member not found', ErrorCodeEnum::AUTHORIZATION_INVALID->value);
        }

        $this->account = $account;
        $this->member = $account->member;
        $this->vendorInfo = $vendorInfo;
    }

    private function validateMtCode(string $mtCode, string $type, string $round): void
    {
        $parts = explode('-', $mtCode);
        if (count($parts) !== 3 || empty($parts[0])) {
            throw new Exception('Invalid mtCode format', ErrorCodeEnum::BAD_PARAMETERS->value);
        }
        if ($parts[1] !== $type) {
            throw new Exception("Invalid mtCode format: not $type", ErrorCodeEnum::BAD_PARAMETERS->value);
        }
        if ($parts[2] !== $round) {
            throw new Exception('Invalid mtCode format: round mismatch', ErrorCodeEnum::BAD_PARAMETERS->value);
        }
    }

    /**
     * 棋牌（BOARDCARD）桌：rollout amount 是帶入金額，rollin 的 amount／win 都是用戶目前餘額
     */
    private function isBoardCardTable(string $tableName): bool
    {
        return str_starts_with($tableName, 'BOARDCARD#');
    }

    /**
     * 真人遊戲應滿足 amount = rollout + win − rake − roomfee；對不上仍照 amount 入帳，只記警告供人工核對
     */
    private function warnIfSettleAmountMismatch(RollinRequest $request, string $rolloutAmount, string $settleAmount): void
    {
        $rate = $this->vendorInfo->rate;
        $netWin = Common::numSub(
            Common::numSub($request->input('win'), $request->input('rake')),
            $request->input('roomfee', '0'),
        );
        $expectedSettleAmount = Common::numAdd($rolloutAmount, Common::numDiv($netWin, $rate), 4);

        if (Common::numCompare($expectedSettleAmount, $settleAmount, 4) === 0) {
            return;
        }

        Log::warning($this->actionPrefix . 'rollin_amount_mismatch', [
            'round' => $request->input('round'),
            'rollout_amount' => $rolloutAmount,
            'settle_amount' => $settleAmount,
            'expected_settle_amount' => $expectedSettleAmount,
            'params' => Common::maskCredentials($request->all()),
        ]);
    }

    private function errorLogAndResponse(int|string $code, string $message): JsonResponse
    {
        Log::error($message, $this->requestInfos);

        return response()->json($this->getResponseArray((int) $code, $message), 200);
    }

    private function getResponseArray(int $code = 0, string $message = 'success', array $data = []): array
    {
        return [
            'status' => [
                'code' => (string) $code,
                'message' => $message,
                'datetime' => now()->tz('-04:00')->toRfc3339String(),
                'tracecode' => Context::getHidden(ContextKeyEnum::REQUEST_ID->value),
            ],
            'data' => $data,
        ];
    }
}
