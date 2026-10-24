<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\IpNotAllowedException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\SignatureVerificationException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\AllowsUnboundAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\RendersCallbackErrors;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyIpWhitelist;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyRequestSignature;
use App\Http\Controllers\External\SingleWallets\Data\AccountIdentifier;
use App\Http\Requests\External\MotivationSW\BetRequest;
use App\Http\Requests\External\MotivationSW\CreditRequest;
use App\Http\Requests\External\MotivationSW\DebitRequest;
use App\Http\Requests\External\MotivationSW\EndroundRequest;
use App\Http\Requests\External\MotivationSW\RefundRequest;
use App\Http\Requests\External\MotivationSW\RollinRequest;
use App\Models\TotalBetRecord;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\MotivationswAccount;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Datas\VendorInfoDetailData;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use App\Vendors\MotivationSW\Enums\StatusCodeEnum;
use App\Vendors\SexySlotCq9SW\Enums\VendorGameTypeEnum;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 前置檢查全部交給 BaseController::callAction() 依 marker 執行：IP 白名單 → 解析帳號／會員／商戶
 * （checkPlayer、balance 允許未綁定會員的池帳號；驗商戶歸屬，不驗帳號與商戶 status：停用後結算、退款、查餘額仍要通）
 * → 比對 wtoken。帳號與商戶停用只擋 bet／rollout，由 verifyBettability() 以 BetException 擋下。
 * 不覆寫 callAction：例外由 renderCallbackError() 翻成廠商錯誤碼。
 *
 * 路由方法一律 public 並掛 #[Endpoint]，其餘方法 private。
 *
 * @knowledge rules/vendor-motivationsw
 */
#[Group('MotivationSW 回調')]
class MotivationSWController extends BaseController implements AllowsUnboundAccount, RendersCallbackErrors, ShouldResolveVendorAccount, ShouldVerifyGameBettability, ShouldVerifyIpWhitelist, ShouldVerifyRequestSignature
{
    protected int $vendorId = VendorIdEnum::MOTIVATIONSW->value;

    protected string $accountClass = MotivationswAccount::class;

    protected string $betRecordClass = MotivationswBetRecord::class;

    protected string $actionPrefix = 'Motivation_SW_';

    protected string $vendorInfoOperatorField = 'token';

    /**
     * action 內以 Exception 第二參數帶 StatusCodeEnum 值的，照該值回；BetException（下注資格不符）回
     * OPERATOR_INTERNAL_SERVER_ERROR 並保留原因；其餘非預期例外（含 QueryException，code 是 SQLSTATE 字串）
     * 一律回 OPERATOR_INTERNAL_SERVER_ERROR 且訊息固定：DB 錯誤訊息含 SQL 與參數，只進 log。
     */
    public function renderCallbackError(Throwable $e): JsonResponse
    {
        $code = $e->getCode();
        $actionStatus = is_int($code) ? StatusCodeEnum::tryFrom($code) : null;

        return match (true) {
            $e instanceof VendorAccountException,
            $e instanceof MemberNotFoundException,
            $e instanceof VendorNotFoundException => $this->errorResponse(StatusCodeEnum::PLAYER_NOT_FOUND, $e->getMessage()),
            $e instanceof IpNotAllowedException,
            $e instanceof SignatureVerificationException,
            $e instanceof BetException => $this->errorResponse(StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR, $e->getMessage()),
            $actionStatus !== null => $this->errorResponse($actionStatus, $e->getMessage()),
            default => $this->systemErrorResponse($e),
        };
    }

    public function accountIdentifier(Request $request, string $method): AccountIdentifier
    {
        return AccountIdentifier::username((string) ($request->route('account') ?? $request->input('account', '')));
    }

    /**
     * 廠商會對尚未綁定會員的池帳號查「是否存在」與「餘額」，須回 data=true／balance=0，不能回查無帳號。
     */
    public function methodsAllowingUnboundAccount(): array
    {
        return ['checkPlayer', 'balance'];
    }

    public function isRequestSignatureValid(Request $request): bool
    {
        $detail = VendorInfoDetailData::from($this->vendorInfo->info);

        return $detail->token === $request->header('wtoken');
    }

    /**
     * 查玩家是否存在。
     *
     * 帳號解析通過就回 data=true；尚未綁定會員的池帳號也算存在，廠商拿它探測開局。
     */
    #[Endpoint(title: '查玩家', operationId: 'motivationsw.checkPlayer')]
    public function checkPlayer(): JsonResponse
    {
        return response()->json([
            'data' => true,
            'status' => ['code' => '0', 'message' => 'Success', 'datetime' => $this->getDateTime()],
        ]);
    }

    /**
     * 查玩家餘額（廠商幣別）。
     *
     * 尚未綁定會員的池帳號回 0。
     */
    #[Endpoint(title: '查餘額', operationId: 'motivationsw.balance')]
    public function balance(): JsonResponse
    {
        if (is_null($this->member)) {
            return $this->successResponse(0);
        }

        return $this->successResponse(
            (float) (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member)
        );
    }

    /**
     * 下注扣款。
     *
     * bet 與 rollout 兩條路由共用這支，依 URL 最後一段決定注單狀態寫 BET 或 ROLLOUT；
     * rollout 是牌桌類把額度轉進遊戲，之後由 rollin 結回。
     */
    #[Endpoint(title: '下注／轉出', operationId: 'motivationsw.bet')]
    public function bet(BetRequest $request): JsonResponse
    {
        $lastSegment = $request->segment(count($request->segments()));
        $status = ($lastSegment === 'bet') ? BetRecordStatusEnum::BET : BetRecordStatusEnum::ROLLOUT;

        $this->verifyBettability((string) $request->input('gamecode'));

        try {
            DB::beginTransaction();

            $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);
            if (Common::numCompare($amount, 0) <= 0) {
                throw new Exception('Bet amount must be greater than 0', StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }

            $betRecord = new MotivationswBetRecord([
                'bet_id' => $request->input('roundid'),
                'username' => $this->account->username,
                'game_code' => $request->input('gamecode'),
                'mtcode' => $request->input('mtcode'),
                'status' => $status,
                'bet' => $amount,
                'valid_bet' => $amount,
                'win_lose' => Common::numMul($amount, -1),
                'rolling_chips' => '0',
                'bet_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'infos' => json_encode($request->all()),
            ]);

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $totalBalance = Common::numAdd($walletDto->wallet->balance, $walletDto->rollingChipWallet?->balance ?? 0);
            if (Common::numCompare(Common::numSub($totalBalance, $amount), 0) < 0) {
                throw new Exception('Wallet balance not enough', StatusCodeEnum::INSUFFICIENT_BALANCE->value);
            }

            $this->changeBalance($this->member, $walletDto, null, $betRecord);

            DB::commit();

            return $this->successResponse(
                (float) Common::numMul(
                    Common::numAdd($walletDto->wallet->balance, $walletDto->rollingChipWallet?->balance ?? 0),
                    $this->vendorInfo->rate,
                    4
                )
            );
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->errorInfo[1] === 1062) {
                throw new Exception("Place bet bet_id duplicate, bet_id: {$request->input('roundid')}", StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 退款：依 mtcode 找到原注單，把投注額退回錢包並將注單標為 REFUND。
     */
    #[Endpoint(title: '退款', operationId: 'motivationsw.refund')]
    public function refund(RefundRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $betRecord = MotivationswBetRecord::lockForUpdate()
                ->where('mtcode', $request->input('mtcode'))
                ->first();

            if (is_null($betRecord)) {
                throw new Exception("Game mtcode {$request->input('mtcode')} not found", StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }

            if ($betRecord->status === BetRecordStatusEnum::REFUND) {
                throw new Exception("Game mtcode {$request->input('mtcode')} already has refunded", StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $cancelledRecord = clone $betRecord;
            $cancelledRecord->bet = '0';
            $cancelledRecord->valid_bet = '0';
            $cancelledRecord->win_lose = '0';
            $cancelledRecord->status = BetRecordStatusEnum::REFUND;
            $cancelledRecord->result_infos = json_encode($request->all());

            $this->changeBalance($this->member, $walletDto, $betRecord, $cancelledRecord);

            DB::commit();

            return $this->successResponse(
                (float) Common::numMul(
                    Common::numAdd($walletDto->wallet->balance, $walletDto->rollingChipWallet?->balance ?? 0),
                    $this->vendorInfo->rate,
                    4
                )
            );
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 結算派彩：同一 roundid 的多筆注單一次結算，派彩入帳並建立總注單。
     */
    #[Endpoint(title: '結算派彩', operationId: 'motivationsw.endround')]
    public function endround(EndroundRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            if (MotivationswBetRecord::where('bet_id', $request->input('roundid'))->where('status', BetRecordStatusEnum::ENDROUND)->exists()) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
                DB::commit();

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4)
                );
            }

            // 這裡只彙總下注列（此時還沒有 ENDROUND 列），派彩由下方每一列以 $endroundRecord 交給
            // createOrUpdate() 累加：首列建總注單時兩者相加，之後每列再加該列派彩。
            // 不可把彙總列同時當 settle 記錄傳：那樣總注單的 win_lose 只會有 -bet，派彩算不進去。
            $totalBetRecord = MotivationswBetRecord::lockForUpdate()
                ->select('id', 'bet_id', 'bet_time', 'game_code', 'updated_at')
                ->selectRaw('SUM(bet) as bet')
                ->selectRaw('SUM(valid_bet) as valid_bet')
                ->selectRaw('SUM(win_lose) as win_lose')
                ->selectRaw('SUM(rolling_chips) as rolling_chips')
                ->where('bet_id', $request->input('roundid'))
                ->groupBy('bet_id')
                ->orderBy('id', 'asc')
                ->first();

            if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $balance = 0.0;
            $resultDate = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();

            foreach (json_decode($request->input('data'), true) as $row) {
                $amount = Common::numDiv($row['amount'], $this->vendorInfo->rate);

                $endroundRecord = new MotivationswBetRecord([
                    'bet_id' => $request->input('roundid'),
                    'username' => $request->input('account'),
                    'game_code' => $request->input('gamecode'),
                    'mtcode' => $row['mtcode'],
                    'bet' => '0',
                    'valid_bet' => '0',
                    'win_lose' => $amount,
                    'rolling_chips' => '0',
                    'status' => BetRecordStatusEnum::ENDROUND,
                    'infos' => json_encode($request->all()),
                    'result_date' => $resultDate,
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $endroundRecord);

                $endroundRecord->payout_time = $resultDate;
                $endroundRecord->updated_at = now();

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->createOrUpdate($totalBetRecord, $endroundRecord, $transactionLogId, 'motivationsw');

                $balance = (float) Common::numMul(
                    Common::numAdd($walletDto->wallet->balance, $walletDto->rollingChipWallet?->balance ?? 0),
                    $this->vendorInfo->rate,
                    4
                );
            }

            DB::commit();

            return $this->successResponse($balance);
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->errorInfo[1] === 1062) {
                throw new Exception("Place bet bet_id duplicate, bet_id: {$request->input('roundid')}", StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 轉回：牌桌類遊戲結束後，把 rollout 轉出的額度連同輸贏結回錢包。
     */
    #[Endpoint(title: '轉回', operationId: 'motivationsw.rollin')]
    public function rollin(RollinRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $rolloutRecord = MotivationswBetRecord::lockForUpdate()
                ->where('bet_id', $request->input('roundid'))
                ->where('status', BetRecordStatusEnum::ROLLOUT)
                ->first();

            if (!is_object($rolloutRecord) || empty($rolloutRecord->id)) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $bet = Common::numDiv($request->input('bet'), $this->vendorInfo->rate);
            // 老虎機／街機／魚機的 validbet 固定為 0，有效投注改取 bet（見 VendorGameTypeEnum::usesBetAsValidBet）
            $validBet = (VendorGameTypeEnum::tryFrom((string) $request->input('gametype'))?->usesBetAsValidBet() ?? false)
                ? $bet
                : Common::numDiv($request->input('validbet'), $this->vendorInfo->rate);
            $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);
            $winLose = Common::numSub($amount, $rolloutRecord->bet);
            $resultDate = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();

            $rollinRecord = new MotivationswBetRecord([
                'bet_id' => $request->input('roundid'),
                'username' => $request->input('account'),
                'game_code' => $request->input('gamecode'),
                'mtcode' => $request->input('mtcode'),
                'bet' => $bet,
                'valid_bet' => $validBet,
                'win_lose' => $winLose,
                'rolling_chips' => $rolloutRecord->rolling_chips,
                'status' => BetRecordStatusEnum::ROLLIN,
                'infos' => json_encode($request->all()),
                'bet_time' => $rolloutRecord->bet_time,
                'result_date' => $resultDate,
            ]);

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $rolloutRecord, $rollinRecord);

            $rollinRecord->payout_time = $resultDate;
            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletDto->isMultipleWallet,
            ))->createOrUpdate($rollinRecord, $rollinRecord, $transactionLogId, 'motivationsw');

            DB::commit();

            return $this->successResponse(
                (float) Common::numMul(
                    Common::numAdd($walletDto->wallet->balance, $walletDto->rollingChipWallet?->balance ?? 0),
                    $this->vendorInfo->rate,
                    4
                )
            );
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->errorInfo[1] === 1062) {
                throw new Exception("Place bet bet_id duplicate, bet_id: {$request->input('roundid')}", StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR->value);
            }
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 對已結算（endround 或 rollin 之後）的注單扣款，注單狀態改為 DEBIT 並同步總注單。
     *
     * 廠商文件的 Debit `amount` 是扣款金額，所以傳負數進共用的事後調整。
     */
    #[Endpoint(title: '事後扣款', operationId: 'motivationsw.debit')]
    public function debit(DebitRequest $request): JsonResponse
    {
        $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

        return $this->adjustSettledRound($request, Common::numMul($amount, -1, 4), BetRecordStatusEnum::DEBIT);
    }

    /**
     * 對已結算（endround 或 rollin 之後）的注單補款，並同步總注單。
     */
    #[Endpoint(title: '事後補款', operationId: 'motivationsw.credit')]
    public function credit(CreditRequest $request): JsonResponse
    {
        $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

        return $this->adjustSettledRound($request, $amount, BetRecordStatusEnum::CREATE);
    }

    /**
     * debit 與 credit 共用的事後調整：找到該局已結算的注單，把輸贏加上 $signedAmount（扣款為負、補款為正），
     * 再以「調整前／調整後」兩份注單交給 changeBalance() 推算錢包變動。
     *
     * 錢包方向由注單輸贏的差額決定，不在這裡另寫加減：先前 debit 把注單輸贏減掉、錢包卻用正數加上去，
     * 兩邊自相矛盾，就是因為各寫一份。注單的投注不變，changeBalance() 不會動禮碼，只動真金。
     *
     * 餘額不足只在扣款時可能發生：補款 $signedAmount 為正，條件永遠不成立。
     */
    private function adjustSettledRound(Request $request, string $signedAmount, BetRecordStatusEnum $status): JsonResponse
    {
        try {
            DB::beginTransaction();

            $betRecord = MotivationswBetRecord::lockForUpdate()
                ->where('bet_id', $request->input('roundid'))
                ->whereIn('status', [BetRecordStatusEnum::ENDROUND, BetRecordStatusEnum::ROLLIN])
                ->first();

            if (!is_object($betRecord) || empty($betRecord->id)) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            if (Common::numCompare(Common::numAdd($walletDto->wallet->balance, $signedAmount, 4), 0) < 0) {
                $msg = "Debit amount is greater than wallet balance, amount: {$signedAmount}, balance: {$walletDto->wallet->balance}, roundid: {$request->input('roundid')}";
                BotNotify::channel('error')->send($msg);
                throw new Exception($msg, StatusCodeEnum::INSUFFICIENT_BALANCE->value);
            }

            $beforeRecord = clone $betRecord;

            $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $signedAmount, 4);
            $betRecord->result_date = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();
            $betRecord->status = $status;
            $betRecord->result_infos = json_encode($request->all());

            $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

            $this->updateTotalBetRecord($betRecord, $signedAmount);

            DB::commit();

            return $this->successResponse(
                (float) Common::numMul($walletDto->wallet->balance, $this->vendorInfo->rate, 4)
            );
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getDateTime(): string
    {
        return now()->setTimezone(new \DateTimeZone('-04:00'))->format('c');
    }

    private function successResponse(float $balance): JsonResponse
    {
        return response()->json([
            'data' => [
                'balance' => $balance,
                'currency' => $this->vendorInfo->currency,
            ],
            'status' => ['code' => '0', 'message' => 'Success', 'datetime' => $this->getDateTime()],
        ]);
    }

    /**
     * 非業務例外（DB 錯誤、程式錯誤）的回應：真實訊息與 trace 只進 log，回給三方的訊息固定。
     */
    private function systemErrorResponse(Throwable $e): JsonResponse
    {
        Log::error($this->actionPrefix . 'error', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'trace' => Common::formatTrace($e),
        ]);

        return $this->errorResponse(StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR, 'Internal server error');
    }

    private function errorResponse(StatusCodeEnum $status, string $errorMessage = ''): JsonResponse
    {
        $errorCode = $status->value;
        $responseData = [
            'data' => null,
            'status' => [
                'code' => (string) $errorCode,
                'message' => $errorMessage,
                'datetime' => $this->getDateTime(),
            ],
        ];

        Log::error($this->actionPrefix . "error_{$errorCode}", [
            'requestData' => [
                'headers' => Common::flattenHeaders(request()->headers->all()),
                'params' => Common::maskCredentials(request()->all()),
            ],
            'responseData' => $responseData,
        ]);

        return response()->json($responseData);
    }

    /**
     * 事後調整後同步總注單：$signedAmount 與注單輸贏同向（扣款為負、補款為正）。
     */
    private function updateTotalBetRecord(MotivationswBetRecord $betRecord, string $signedAmount): void
    {
        $totalBetRecord = TotalBetRecord::lockForUpdate()
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('bet_record_id', $betRecord->id)
            ->first();

        if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
            return;
        }

        $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $signedAmount, 4);
        // 本家的派彩時間欄位是 result_date，payout_time 不是欄位，讀到 null 會撞總注單的 NOT NULL
        $totalBetRecord->payout_time = $betRecord->result_date;

        if (Common::numCompare(Common::numAdd($totalBetRecord->win_lose, $totalBetRecord->bet), 0) === 0) {
            $totalBetRecord->delete();
        } else {
            $totalBetRecord->save();
        }
    }
}
