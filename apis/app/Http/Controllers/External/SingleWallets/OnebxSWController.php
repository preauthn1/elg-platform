<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Requests\External\OnebxSW as OnebxSWRequest;
use App\Models\TotalBetRecord;
use App\Services\BotNotifyService;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\OnebxswAccount;
use App\Vendors\Models\OnebxswBetRecord;
use App\Vendors\OnebxSW\Datas\VendorInfoDetail;
use App\Vendors\OnebxSW\Enums\BetStatusEnum;
use App\Vendors\OnebxSW\Enums\ErrorCodeEnum;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OneBX 體育單一錢包回調。
 *
 * 一般端點與 step/* 端點共用同一套處理：step 版只差欄位名（credit_amount ↔ deduct_amount／amount_bet）。
 * settle／undo／cancel 以 `item` JSON 批次送多筆，每筆各自一個交易，單筆失敗即回錯、前面已提交的不回滾。
 *
 * OneBX 的 game_code 固定為 {@see self::GAME_CODE}（三方不帶遊戲代碼，整個體育大廳視為一款遊戲）。
 *
 * @knowledge rules/vendor-onebxsw
 */
class OnebxSWController extends BaseController implements ShouldVerifyGameBettability
{
    private const string GAME_CODE = 'OneBXSW_Lobby';

    private const int MAX_ITEMS = 30;

    protected int $vendorId = VendorIdEnum::ONEBXSW->value;

    protected string $accountClass = OnebxswAccount::class;

    protected string $betRecordClass = OnebxswBetRecord::class;

    protected string $actionPrefix = 'OnebxSW_request_';

    protected string $vendorInfoOperatorField = 'MERCHANT_ID';

    private array $requestInfos = [];

    private string $currentMethod = '';

    public function callAction($method, $parameters)
    {
        $this->currentMethod = (string) $method;

        try {
            $request = request();
            $this->logRequest($request, $this->actionPrefix . basename(url()->current()));
            $this->requestInfos = [
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => Common::maskCredentials($request->all()),
            ];

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorLogAndResponse(ErrorCodeEnum::INVALID_SIGNATURE, sprintf('IP %s not allow', Common::getIp()));
            }

            // 簽名要在 item 還是原始 JSON 字串時算，先取快照再解碼
            $signedInput = $request->all();
            $items = $this->decodeItems($request);

            $this->setUpVendorAccount(thirdPartyUsername: (string) ($request->input('username') ?? $items['data'][0]['username'] ?? ''));

            // 共用組裝只依外鍵取商戶，補回「必須是本遊戲商」這道
            if ((int) $this->vendorInfo->vendor_id !== $this->vendorId) {
                return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, 'Vendor not found');
            }

            if (!$this->isSignatureValid($signedInput)) {
                return $this->errorLogAndResponse(ErrorCodeEnum::INVALID_SIGNATURE, 'Invalid signature');
            }

            return parent::callAction($method, $parameters);
        } catch (VendorAccountException $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::USER_NOT_FOUND, 'Account not found');
        } catch (MemberNotFoundException $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::USER_NOT_AUTHORIZED_TO_BET, 'Member not active');
        } catch (VendorNotFoundException $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, 'Vendor not found');
        } catch (BetException $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::USER_NOT_AUTHORIZED_TO_BET, 'Account could not bet yet');
        } catch (Exception $e) {
            $code = ErrorCodeEnum::tryFrom((int) $e->getCode()) ?? ErrorCodeEnum::SYSTEM_ERROR;

            return $this->errorLogAndResponse($code, $e->getMessage());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    public function balance(OnebxSWRequest\GetBalanceRequest $request): JsonResponse
    {
        $this->assertAccountActive();

        if (!BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorId)) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::USER_NOT_AUTHORIZED_TO_BET->value);
        }

        return $this->successResponse((new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member));
    }

    public function bet(OnebxSWRequest\BetRequest $request): JsonResponse
    {
        return $this->placeBet($request->all());
    }

    public function stepBet(OnebxSWRequest\StepBetRequest $request): JsonResponse
    {
        return $this->placeBet($request->all());
    }

    public function rollback(OnebxSWRequest\RollbackRequest $request): JsonResponse
    {
        return $this->successResponse($this->refundBet($request->all(), $request->input('credit_amount')));
    }

    /**
     * step 版的 rollback 沒有 credit_amount，退款額是 deduct_amount
     */
    public function stepRollback(OnebxSWRequest\StepRollbackRequest $request): JsonResponse
    {
        return $this->successResponse($this->refundBet($request->all(), $request->input('deduct_amount')));
    }

    public function settle(OnebxSWRequest\SettleRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->settleBet($item));

        return $this->successResponse();
    }

    public function stepSettle(OnebxSWRequest\StepSettleRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->settleBet($item));

        return $this->successResponse();
    }

    public function undo(OnebxSWRequest\UndoRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->undoSettle($item));

        return $this->successResponse();
    }

    public function stepUndo(OnebxSWRequest\StepUndoRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->undoSettle($item));

        return $this->successResponse();
    }

    public function cancel(OnebxSWRequest\CancelRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->refundBet($item, $this->itemAmount($item)));

        return $this->successResponse();
    }

    public function stepCancel(OnebxSWRequest\StepCancelRequest $request): JsonResponse
    {
        $this->eachItem($request, fn (array $item) => $this->refundBet($item, $this->itemAmount($item)));

        return $this->successResponse();
    }

    /**
     * Promotion 發放（`type: promotion`）。**尚未實作**，見 {@see self::logUnimplementedEndpoint()}。
     */
    public function stepBonus(Request $request): JsonResponse
    {
        return $this->logUnimplementedEndpoint($request, 'step/bonus');
    }

    /**
     * Promotion 回收（`type: refund`）。**尚未實作**，見 {@see self::logUnimplementedEndpoint()}。
     */
    public function stepRefundBonus(Request $request): JsonResponse
    {
        return $this->logUnimplementedEndpoint($request, 'step/refund-bonus');
    }

    /**
     * 已掛路由但尚未實作的動錢端點：把三方真實 payload 完整記下來，再回系統錯誤。
     *
     * 為什麼要掛路由：沒有路由就是 404，Laravel 的 RequestLogger 不會跑，我們永遠看不到三方送了什麼——
     * 這兩支的冪等鍵（`transaction_id`／`bill_id`／`id` 哪一個）與金額型別文件都沒寫死，只能等真實請求。
     *
     * 🔴 為什麼不回 200：回 200 等於向 OneBX 承認「已入帳」，它就不再送，玩家的 promotion 直接消失。
     * 回非 200 它會持續重送（文件：重送到收到 HTTP 200 為止），等實作上線後這些待處理的請求會自己補回來。
     */
    private function logUnimplementedEndpoint(Request $request, string $endpoint): JsonResponse
    {
        Log::warning($this->actionPrefix . 'endpoint_not_implemented', [
            'endpoint' => $endpoint,
            'member_id' => $this->member->id,
            'items' => Common::maskCredentials($this->decodeItems($request)['data'] ?? []),
            'params' => Common::maskCredentials($request->all()),
        ]);

        return $this->errorLogAndResponse(
            ErrorCodeEnum::SYSTEM_ERROR,
            "Endpoint {$endpoint} is not implemented yet, request logged for spec confirmation"
        );
    }

    /**
     * 下注：扣 deduct_amount，有效投注記 amount_bet。
     * bet_id 重複（三方重送）回當前餘額當成功，錢不動。
     */
    private function placeBet(array $params): JsonResponse
    {
        $this->assertAccountActive();

        try {
            return DB::transaction(function () use ($params) {
                // 先鎖會員列再做任何一般讀：verifyBettability 會查表，放在鎖前會把 RR 快照定在等鎖之前（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $this->verifyBettability(self::GAME_CODE);

                // 「不該存在」的重複檢查刻意不加鎖：對不存在的唯一鍵 FOR UPDATE 會拿 gap lock，併發 INSERT 互撞成 1213
                if (OnebxswBetRecord::where('bet_id', $params['bettranstion_id'])->exists()) {
                    return $this->successResponse($this->vendorBalance($walletDto->getTotalBalance()));
                }

                $bet = $this->absAmount($params['deduct_amount']);
                if (Common::numCompare($walletDto->getTotalBalance(), $bet, 4) < 0) {
                    throw new Exception('Wallet balance not enough', ErrorCodeEnum::INSUFFICIENT_BALANCE->value);
                }

                $betRecord = new OnebxswBetRecord([
                    'bet_id' => $params['bettranstion_id'],
                    'username' => $this->account->username,
                    'game_code' => self::GAME_CODE,
                    'status' => BetStatusEnum::BET->value,
                    'bet' => $bet,
                    'valid_bet' => Common::numDiv((string) $params['amount_bet'], $this->vendorInfo->rate, 4),
                    'win_lose' => Common::numMul($bet, -1, 4),
                    'rolling_chips' => 0,
                    'bet_time' => $this->requestTime($params),
                    'payout_time' => $this->requestTime($params),
                    'infos' => json_encode([$params]),
                ]);

                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                return $this->successResponse($this->vendorBalance($walletDto->getTotalBalance()));
            });
        } catch (QueryException $e) {
            // 併發重送在 exists() 之後才撞到 unique(bet_id)：整筆已回滾，照重送語意回當前餘額
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->successResponse((new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member));
            }
            throw $e;
        }
    }

    /**
     * 取消下注：把下注額退回；注單若已結算，依文件「the settle result must be reversed as part of the cancel」
     * 一併把派彩收回、刪掉 TBR。找不到注單或已經取消視為已處理，回當前餘額不動錢。
     *
     * TPower 2026-09-17 實撈：對「我方有這張注單」的取消，credit_amount 一律等於 bet（10 筆已取消注單皆然）；
     * credit_amount=0 的 rollback 打的都是我方沒有的單（下注被拒或超時後 OneBX 補送 void），走上面的 no-op。
     * 0 元卻對到存在的注單是異常，回錯誤讓 OneBX 重送、留下訊號，不可默默標成取消而錢沒退。
     *
     * @return string 已乘 rate 的餘額
     */
    private function refundBet(array $params, mixed $refundAmount): string
    {
        return DB::transaction(function () use ($params, $refundAmount) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $betRecord = OnebxswBetRecord::lockForUpdate()->where('bet_id', $params['bettranstion_id'])->first();
            if (!$betRecord || $betRecord->status === BetStatusEnum::CANCELED->value) {
                return $this->vendorBalance($walletDto->getTotalBalance());
            }
            $wasSettled = $betRecord->status === BetStatusEnum::SETTLED->value;

            $refund = $this->absAmount($refundAmount);
            if (Common::numCompare($refund, 0, 4) <= 0) {
                Log::warning($this->actionPrefix . 'rollback_zero_amount_on_existing_bet', [
                    'member_id' => $this->member->id,
                    'bet_id' => $betRecord->bet_id,
                    'bet' => $betRecord->bet,
                    'request_amount' => $refundAmount,
                ]);
                throw new Exception("Rollback amount 0 for existing bet {$betRecord->bet_id}", ErrorCodeEnum::SYSTEM_ERROR->value);
            }
            if (Common::numCompare($refund, $betRecord->bet, 4) > 0) {
                throw new Exception(
                    "Payback amount {$refund} is greater than bet {$betRecord->bet}",
                    ErrorCodeEnum::INSUFFICIENT_BALANCE->value
                );
            }

            // 已結算的單：連派彩一起收回，真金不夠收就不動、發 TG 通知並回錯誤讓三方重送
            $settledWin = $wasSettled ? Common::numAdd($betRecord->win_lose, $betRecord->bet, 4) : '0';
            if ($wasSettled && Common::numCompare(Common::numAdd($walletDto->wallet->balance, $refund, 4), $settledWin, 4) < 0) {
                $this->notifyReverseBalanceNotEnough('Cancel after settle', $params, $settledWin, $walletDto->wallet->balance);
            }

            // 退款額交給 changeBalance 依「bet 差額」推得，禮碼先退、真金補足；autoSave=false 保留注單原本的 bet 欄。
            // win 沒有獨立欄位、由 win_lose + bet 推得：把 win_lose 設成 -bet 就是 win 歸零——未結算時 win 本來就是 0
            // （差額只剩退回本金），已結算時則順帶把派彩收回。
            $beforeBetRecord = clone $betRecord;
            $afterBetRecord = clone $betRecord;
            $afterBetRecord->bet = Common::numSub($betRecord->bet, $refund, 4);
            $afterBetRecord->win_lose = Common::numMul($afterBetRecord->bet, -1, 4);
            $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord, autoSave: false);

            $betRecord->status = BetStatusEnum::CANCELED->value;
            $betRecord->win_lose = $afterBetRecord->win_lose;
            $betRecord->rolling_chips = $afterBetRecord->rolling_chips;
            $betRecord->payout_time = $this->requestTime($params);
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);
            $betRecord->save();

            if ($wasSettled) {
                // 結算已沖銷，這張不該再出現在彙總（預設結算才建 TBR）
                TotalBetRecord::where('vendor_id', $this->vendorId)->where('bet_record_id', $betRecord->id)->delete();
            }

            return $this->vendorBalance($walletDto->getTotalBalance());
        });
    }

    /**
     * 結算：派彩 credit_amount。注單不是「未結算」狀態視為已處理，不動錢。
     */
    private function settleBet(array $params): void
    {
        $credit = Common::numDiv($this->itemAmount($params), $this->vendorInfo->rate, 4);
        if (Common::numCompare($credit, 0, 4) < 0) {
            throw new Exception(
                'Settle amount must be greater than 0, this request should be in Unsettle',
                ErrorCodeEnum::INSUFFICIENT_BALANCE->value
            );
        }

        DB::transaction(function () use ($params, $credit) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $betRecord = OnebxswBetRecord::lockForUpdate()->where('bet_id', $params['bettranstion_id'])->first();
            if (!$betRecord) {
                throw new Exception('Place bet record not found', ErrorCodeEnum::SYSTEM_ERROR->value);
            }
            if ($betRecord->status !== BetStatusEnum::BET->value) {
                $this->warnIfUnknownSettlement($betRecord, $params, $credit);

                return;
            }

            $beforeBetRecord = clone $betRecord;
            $betRecord->status = BetStatusEnum::SETTLED->value;
            $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $credit, 4);
            $betRecord->payout_time = $this->requestTime($params);
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

            (new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet))
                ->create($betRecord, $transactionLogId);
        });
    }

    /**
     * 已結算／已取消的注單又收到 settle。
     *
     * 同一個 call_transaction_id 是三方重送（文件：settle 會重送到收到 HTTP 200 為止，線上看過重送 9 次），靜默即可。
     * 不同的 call_transaction_id 代表沒經過 undo 的重新結算——文件寫「must be applied as a new payout」但沒定義金額語意
     * （補差額還是補全額），TPower／Yes2Win 全部 763 張注單也從未發生過，硬套有多付風險。這裡刻意不動錢，留 warning
     * 帶齊 member_id 與金額，第一次發生時能直接從 log 算出少付了誰、少付多少再人工補。
     * 判定依據是注單 infos 裡每一段已套用 payload 的 call_transaction_id，不另開表；該欄位 2026-04-26 起每筆 settle 都有。
     */
    private function warnIfUnknownSettlement(OnebxswBetRecord $betRecord, array $params, string $credit): void
    {
        $incoming = (string) ($params['call_transaction_id'] ?? '');
        $applied = array_values(array_filter(array_column(json_decode($betRecord->infos ?? '[]', true) ?: [], 'call_transaction_id')));
        if ($incoming === '' || in_array($incoming, $applied, true)) {
            return;
        }

        Log::warning($this->actionPrefix . 'settle_new_call_transaction_id_on_settled_bet', [
            'member_id' => $this->member->id,
            'bet_id' => $betRecord->bet_id,
            'status' => $betRecord->status,
            'incoming_call_transaction_id' => $incoming,
            'applied_call_transaction_ids' => $applied,
            'incoming_credit' => $credit,
            'settled_win' => Common::numAdd($betRecord->win_lose, $betRecord->bet, 4),
        ]);
    }

    /**
     * 取消結算：把派彩收回、注單退回「未結算」。注單不是「已結算」狀態視為已處理，不動錢。
     *
     * 收回額以注單自己記的已結算派彩（win_lose + bet）為準，不看請求的 credit_amount——
     * Dev 2026-09-15～18 撈到的 36 筆 undo 該欄位全是 0（win_side／分數也都是 null），它只是「把這張的結算撤掉」
     * 的指令，金額資訊不在請求裡。原 Libs/Undo 拿 credit_amount 當收回額，贏的注 undo 後一毛不收、
     * 注單卻退回 BET，再 settle 就多派一次。
     * 收回後真金會變負時不扣、發 TG 通知並回錯誤。
     */
    private function undoSettle(array $params): void
    {
        DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $betRecord = OnebxswBetRecord::lockForUpdate()->where('bet_id', $params['bettranstion_id'])->first();
            if (!$betRecord) {
                throw new Exception('Bet record not found', ErrorCodeEnum::SYSTEM_ERROR->value);
            }
            if ($betRecord->status !== BetStatusEnum::SETTLED->value) {
                return;
            }

            $settledWin = Common::numAdd($betRecord->win_lose, $betRecord->bet, 4);
            if (Common::numCompare($walletDto->wallet->balance, $settledWin, 4) < 0) {
                $this->notifyReverseBalanceNotEnough('Unsettle', $params, $settledWin, $walletDto->wallet->balance);
            }

            $beforeBetRecord = clone $betRecord;
            $betRecord->status = BetStatusEnum::BET->value;
            $betRecord->win_lose = Common::numMul($betRecord->bet, -1, 4);
            $betRecord->payout_time = $betRecord->bet_time;
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);

            $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $betRecord);

            // 退回未結算就不該有 TBR（預設結算才建）
            TotalBetRecord::where('vendor_id', $this->vendorId)->where('bet_record_id', $betRecord->id)->delete();
        });
    }

    /**
     * settle／undo／cancel 的 `item` 是批次，逐筆處理、每筆各自一個交易
     */
    private function eachItem(Request $request, callable $handler): void
    {
        $items = $this->decodeItems($request)['data'] ?? [];
        if (count($items) > self::MAX_ITEMS) {
            throw new Exception('Too many items (max ' . self::MAX_ITEMS . ')', ErrorCodeEnum::SYSTEM_ERROR->value);
        }

        // 文件的 cancel body 沒有頂層 timestamp，每筆 item 自帶；`+` 保留 item 自己的鍵
        $timestamp = $request->input('timestamp') ?? time();
        foreach ($items as $item) {
            $handler($item + ['timestamp' => $timestamp]);
        }
    }

    /**
     * item 內的金額欄（三方單位，未換算）：一般端點是 credit_amount，step 端點是 amount_bet
     */
    private function itemAmount(array $item): string
    {
        return (string) ($item['credit_amount'] ?? $item['amount_bet']);
    }

    /**
     * 三方金額換算成平台單位並取絕對值（三方的退款／扣款額正負號不一致）
     */
    private function absAmount(mixed $vendorAmount): string
    {
        $amount = Common::numDiv((string) $vendorAmount, $this->vendorInfo->rate, 4);

        return Common::numCompare($amount, 0, 4) < 0 ? Common::numMul($amount, -1, 4) : $amount;
    }

    private function vendorBalance(string $platformBalance): string
    {
        return Common::numMul($platformBalance, $this->vendorInfo->rate, 4);
    }

    /**
     * OneBX 不帶投注時間，以請求的 timestamp 代替
     */
    private function requestTime(array $params): string
    {
        return date('Y-m-d H:i:s', (int) $params['timestamp']);
    }

    private function assertAccountActive(): void
    {
        if ($this->account->status == 0) {
            throw new Exception('Account is disabled', ErrorCodeEnum::USER_NOT_AUTHORIZED_TO_BET->value);
        }
    }

    /**
     * `item` 是雙層 JSON 字串（外層含 data，data 自己又是 JSON 字串）。
     * 不寫回 request：注入到 action 的 FormRequest 是另一個實例，merge 傳不過去，各自解碼最單純。
     */
    private function decodeItems(Request $request): array
    {
        $raw = $request->input('item');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $items = json_decode($raw, true) ?: [];
        $items['data'] = json_decode((string) ($items['data'] ?? '[]'), true) ?: [];

        return $items;
    }

    /**
     * signature = base64(HMAC-SHA1(http_build_query(ksort(全部欄位 - signature)), SECRET_KEY))
     */
    private function isSignatureValid(array $input): bool
    {
        $signature = (string) ($input['signature'] ?? '');
        unset($input['signature']);
        ksort($input);

        $secretKey = (string) (VendorInfoDetail::from($this->vendorInfo->info)->SECRET_KEY ?? '');
        if ($secretKey === '') {
            Log::warning($this->actionPrefix . 'secret_key_not_configured', [
                'vendor_info_id' => $this->vendorInfo->id,
                'reason' => 'info.SECRET_KEY 未設定，回調一律拒收；請補上該站商戶設定',
            ]);

            return false;
        }

        return hash_equals(base64_encode(hash_hmac('SHA1', http_build_query($input), $secretKey, true)), $signature);
    }

    private function notifyReverseBalanceNotEnough(string $action, array $params, string $settledWin, string $walletBalance): never
    {
        $msg = "OnebxSW {$action} amount {$settledWin} is greater than wallet balance, \n"
            . "request credit_amount: {$this->itemAmount($params)}, \n"
            . "wallet balance: {$walletBalance}, \n"
            . "bettranstion_id: {$params['bettranstion_id']}, \n"
            . "member id: {$this->member->id}\n";

        (new BotNotifyService)->channel('warn')->send($msg);
        throw new Exception($msg, ErrorCodeEnum::INSUFFICIENT_BALANCE->value);
    }

    private function successResponse(?string $balance = null): JsonResponse
    {
        $body = ['status' => 'success'];
        if ($balance !== null) {
            $body['balance'] = (float) $balance;
        }

        return response()->json($body);
    }

    /**
     * 回應碼與 HTTP 狀態碼對照沿用改版前，三方是照這組解讀的。
     *
     * 🔴 唯一例外：settle／stepSettle 的系統錯誤不得回 E_00。文件明訂 /api/settle 收到非 200 且 body 為 E_00 時，
     * 整批派彩標成永久失敗、永不重送——那批玩家永遠收不到錢。其餘任何狀態碼都會讓重送迴圈繼續，
     * 所以這裡改回 RETRY（不在三方碼表內，body 除了 E_00 之外不會被解讀），HTTP 仍是 500。
     */
    private function errorLogAndResponse(ErrorCodeEnum $code, string $message = ''): JsonResponse
    {
        $isSettle = in_array($this->currentMethod, ['settle', 'stepSettle'], true);

        [$status, $httpStatus] = match ($code) {
            ErrorCodeEnum::SYSTEM_ERROR => [$isSettle ? 'RETRY' : 'E_00', 500],
            ErrorCodeEnum::USER_NOT_AUTHORIZED_TO_BET => ['B_00', 401],
            ErrorCodeEnum::USER_NOT_FOUND => ['C_01', 401],
            ErrorCodeEnum::INVALID_SIGNATURE => ['M_00', 401],
            ErrorCodeEnum::INSUFFICIENT_BALANCE => ['C_00', 400],
            ErrorCodeEnum::EXCEED_DAILY_WIN_LIMIT => ['B_02', 400],
        };

        Log::info('OnebxSW_error_log', [
            'errorCode' => $status,
            'errorMessage' => $message,
            'requestInfos' => $this->requestInfos,
            'endpoint' => url()->current(),
        ]);

        return response()->json(['status' => $status], $httpStatus);
    }
}
