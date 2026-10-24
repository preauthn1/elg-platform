<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\RgswAccount;
use App\Vendors\Models\RgswBetRecord;
use App\Vendors\RGSW\Enums\BetRecordStatusEnum;
use App\Vendors\RGSW\Enums\CorrectionTypeEnum;
use App\Vendors\RGSW\Enums\StatusCodeEnum;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-rgsw
 */
class RgswController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::RGSW->value;

    protected string $accountClass = RgswAccount::class;

    protected string $betRecordClass = RgswBetRecord::class;

    protected string $actionPrefix = 'RGSW_';

    protected string $vendorInfoOperatorField = 'token';

    /**
     * 同一筆調整在這個秒數內再次進來，就視為遊戲商重送（判斷細節見 isDuplicateCorrection）。
     *
     * ⚠️ 300 秒沒有 RG 文件依據，而且**沒辦法用歷史資料校準**：實測 TPower 注單 info 裡的 453 筆
     * corrections（調整紀錄），每張注單都只有一筆，代表生產上從來沒發生過任何重送，無從得知間隔。
     * 300 秒取的是一般 HTTP 逾時重送的常見量級；完整取捨與待 RG 確認的項目見
     * docs/integrations/vendors/RGSW/integration.md。
     *
     * 用 public 是為了讓測試引用得到：這個值一旦調整，寫死秒數的測試會靜默地變成在測另一件事。
     */
    public const DUPLICATE_WINDOW_SECONDS = 300;

    /**
     * 金額比大小時的小數位數。
     *
     * 一定要指定：`Common::numCompare()` 預設 scale 是 10，而注單金額欄位是 decimal(18,4)。
     * 拿「除以 rate 之後還帶 10 位小數的請求金額」去比「已經被 DB 收到 4 位的注單金額」，
     * rate 不是 1 的白標就會出現「全額退款卻被判成超額而永遠退不掉」或「退完仍留 0.0001 以下的
     * 殘量、注單永遠關不了」。用 4 位比，等於承認「小於一個最小單位的差額就是相等」。
     */
    protected const AMOUNT_SCALE = 4;

    // ─── GET /user/check/{username} ───────────────────────────────────────────

    public function checkPlayer(Request $request, string $username): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername($username) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        return response()->json([
            'status' => ['code' => StatusCodeEnum::SUCCESS->value, 'message' => 'Success'],
            'data' => ['exist' => true],
        ]);
    }

    // ─── GET /user/balance/{username} ─────────────────────────────────────────

    public function balance(Request $request, string $username): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername($username) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if (!BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorId)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        // getSingleWalletBalanceByMember 已乘 rate，直接回傳
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->successResponse((float) $balance);
    }

    // ─── POST /game/bet ───────────────────────────────────────────────────────

    public function bet(Request $request): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername((string) $request->input('username')) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $request->input('game_code', ''), $this)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $betAmount = Common::numDiv((string) $request->input('bet_total'), $rate);
                $validBet = Common::numDiv((string) $request->input('bet_real', $request->input('bet_total')), $rate);

                if (Common::numCompare($walletDto->getTotalBalance(), $betAmount) < 0) {
                    return $this->errorResponse(StatusCodeEnum::INSUFFICIENT_BALANCE);
                }

                $betRecord = new RgswBetRecord([
                    'vendor_info_id' => $this->account->vendor_info_id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $request->input('bet_no'),
                    'reference_no' => $request->input('order_sn'),
                    'game_code' => (string) $request->input('game_code', ''),
                    'game_type' => (string) $request->input('game_type', ''),
                    'status' => BetRecordStatusEnum::BET->value,
                    'bet' => $betAmount,
                    'valid_bet' => $validBet,
                    'win_lose' => Common::numMul($betAmount, -1),
                    'win' => 0,
                    'bet_time' => $this->parseVendorTime($request->input('bet_time')),
                    // 未結算注單無 payout_time，帶 sentinel 避免 total_bet_records.payout_time 無預設值寫入失敗
                    'payout_time' => '1970-01-01 00:00:00',
                    'info' => json_encode($request->all()),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'bet_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/payout ────────────────────────────────────────────────────

    public function payout(Request $request): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername((string) $request->input('username')) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request) {
                $rate = $this->vendorInfo->rate;
                $payoutTime = $this->parseVendorTime($request->input('payout_time'));

                // 鎖序統一為「會員 → 注單」，與 bet()／refund() 等路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $betRecord = RgswBetRecord::lockForUpdate()
                    ->where('bet_id', $request->input('bet_no'))
                    ->where('status', BetRecordStatusEnum::BET->value)
                    ->firstOrFail();

                $win = Common::numDiv((string) $request->input('amount', 0), $rate);
                $validBet = Common::numDiv((string) $request->input('bet_real', 0), $rate);
                $rebate = Common::numDiv((string) $request->input('rebate', 0), $rate);

                // RG SW 的 order_sn 對 payout 一律等於 bet_no，跟 bet() 建立時的 reference_no 撞號，
                // 故不新增記錄，改為直接更新原注單（bet 已在下注時扣款，這裡只補上派彩金額）
                $beforeRecord = clone $betRecord;
                $betRecord->win = $win;
                $betRecord->win_lose = Common::numSub($win, $betRecord->bet, 4);
                $betRecord->valid_bet = $validBet;
                $betRecord->rebate = $rebate;
                $betRecord->status = BetRecordStatusEnum::PAYOUT;
                $betRecord->payout_time = $payoutTime;
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::PAYOUT, $request);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                $betRecord->save();

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'payout_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/endRound ──────────────────────────────────────────────────

    public function endRound(Request $request): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername((string) $request->input('username')) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request) {
                $rate = $this->vendorInfo->rate;
                $payoutTime = $this->parseVendorTime($request->input('payout_time'));
                $data = json_decode($request->input('data'), true);

                // 鎖序統一為「會員 → 注單」，與 bet()／refund() 等路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $betRecord = RgswBetRecord::lockForUpdate()
                    ->where('bet_id', $request->input('bet_no'))
                    ->where('status', BetRecordStatusEnum::BET->value)
                    ->firstOrFail();

                $totalBetService = new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                );

                // RG SW 的 order_sn 對 endRound 各筆 item 一律等於 bet_no，跟 bet() 建立時的 reference_no 撞號，
                // 故不新增記錄，改為將多筆派彩疊加進原注單（bet 已在下注時扣款，這裡只累加派彩金額）
                $betRecord->status = BetRecordStatusEnum::ENDROUND;
                $betRecord->payout_time = $payoutTime;
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::END_ROUND, $request);

                foreach ($data as $item) {
                    $amount = Common::numDiv((string) $item['money'], $rate);

                    $beforeRecord = clone $betRecord;
                    $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                    $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);

                    $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                    $betRecord->save();

                    $totalBetService->create($betRecord, $transactionLogId);
                }

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'endRound_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/betPayout ─────────────────────────────────────────────────

    public function betPayout(Request $request): JsonResponse
    {
        if (!$this->ipAllowed() || !$this->resolveByUsername((string) $request->input('username')) || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $request->input('game_code', ''), $this)) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $betTotal = Common::numDiv((string) $request->input('bet_total'), $rate);
                $payoff = Common::numDiv((string) $request->input('payoff'), $rate);
                $validBet = Common::numDiv((string) $request->input('bet_real', $request->input('bet_total')), $rate);
                $rebate = Common::numDiv((string) $request->input('rebate', 0), $rate);
                $jackpot = Common::numDiv((string) $request->input('jackpot', 0), $rate);

                // 投注前餘額必須 >= 投注全額（bet_total），不可用「餘額 - 投注 + 派彩」的淨額判斷
                if (Common::numCompare($walletDto->getTotalBalance(), $betTotal) < 0) {
                    return $this->errorResponse(StatusCodeEnum::INSUFFICIENT_BALANCE);
                }

                // win=betTotal+payoff（派彩金額）; win_lose=payoff（淨輸贏）
                $betPayoutRecord = new RgswBetRecord([
                    'vendor_info_id' => $this->account->vendor_info_id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $request->input('bet_no'),
                    'reference_no' => $request->input('order_sn'),
                    'game_code' => (string) $request->input('game_code', ''),
                    'game_type' => (string) $request->input('game_type', ''),
                    'status' => BetRecordStatusEnum::BETPAYOUT->value,
                    'bet' => $betTotal,
                    'valid_bet' => $validBet,
                    'win_lose' => $payoff,
                    'win' => Common::numAdd($betTotal, $payoff, 4),
                    'rebate' => $rebate,
                    'jackpot' => $jackpot,
                    'bet_time' => $this->parseVendorTime($request->input('bet_time')),
                    'payout_time' => $this->parseVendorTime($request->input('payout_time')),
                    'info' => json_encode($request->all()),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betPayoutRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betPayoutRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'betPayout_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/refund ────────────────────────────────────────────────────

    public function refund(Request $request): JsonResponse
    {
        // refund 只傳 order_sn，從 BetRecord 反查 username 再驗 wtoken；order_sn 與下注時的 bet_id 同值
        // 遊戲商確認：refund 可能發生在未結算（BET）或已結算（PAYOUT/ENDROUND/BETPAYOUT）注單
        $betRecord = $this->resolveByBetId((string) $request->input('order_sn'), [
            BetRecordStatusEnum::BET,
            BetRecordStatusEnum::PAYOUT,
            BetRecordStatusEnum::ENDROUND,
            BetRecordStatusEnum::BETPAYOUT,
        ]);
        if (!$betRecord || !$this->ipAllowed() || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::RECORD_NOT_FOUND);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request, $betRecord) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $amount = Common::numDiv((string) $request->input('amount'), $rate);

                $betRecord = RgswBetRecord::whereKey($betRecord->id)->lockForUpdate()->first();

                if ($this->isDuplicateCorrection($betRecord, CorrectionTypeEnum::REFUND, $request)) {
                    return $this->duplicateCorrectionResponse(CorrectionTypeEnum::REFUND, $betRecord, $walletDto->getTotalBalance(), $amount);
                }

                // 退款額必須是正數：負數會讓下面的加減反向，變成從玩家錢包倒扣
                if (Common::numCompare($amount, '0', self::AMOUNT_SCALE) <= 0) {
                    Log::warning($this->actionPrefix . 'refund_amount_not_positive', [
                        'bet_id' => $betRecord->bet_id,
                        'refund_amount' => $amount,
                    ]);

                    return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
                }

                $beforeRecord = clone $betRecord;

                if ($betRecord->status === BetRecordStatusEnum::BET) {
                    // 未結算：縮減/取消這筆還沒開獎的注，自動退還禮碼
                    // 退款額大於剩餘下注額時直接拒絕：照原式算下去 bet 會變負數，
                    // changeBalance() 以 bet 差額入帳等於退還玩家沒下過的錢
                    if (Common::numCompare($amount, $betRecord->bet, self::AMOUNT_SCALE) > 0) {
                        Log::warning($this->actionPrefix . 'refund_exceeds_remaining_bet', [
                            'bet_id' => $betRecord->bet_id,
                            'remaining_bet' => $betRecord->bet,
                            'refund_amount' => $amount,
                        ]);

                        return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
                    }

                    $betRecord->bet = Common::numSub($betRecord->bet, $amount);
                    $betRecord->win_lose = Common::numMul($betRecord->bet, -1);

                    // 部分退款後這一注還在進行中，狀態必須留在 BET：翻成 REFUND 的話，
                    // 之後合法的第二筆部分退款會查不到注單（resolveByBetId 不收 REFUND）而少退
                    if (Common::numCompare($betRecord->bet, '0', self::AMOUNT_SCALE) <= 0) {
                        $betRecord->valid_bet = 0;
                        $betRecord->status = BetRecordStatusEnum::REFUND;
                    } else {
                        // RG 未提供部分退款後有效投注的算法，這裡與 bet 同步遞減並以 0 為下限，待三方回覆校準
                        $validBet = Common::numSub($betRecord->valid_bet, $amount, self::AMOUNT_SCALE);
                        $betRecord->valid_bet = Common::numCompare($validBet, '0', self::AMOUNT_SCALE) < 0 ? '0' : $validBet;
                    }
                } else {
                    // 已結算：結算本身不變，只疊加調整金額（跟 debit/credit 同一套邏輯），狀態維持原結算值
                    // changeBalance() 是用 win/bet 的差額算錢包變動，只改 win_lose 不會真的動到餘額，必須連 win 一起改
                    $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                    $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
                }

                // 兩個分支都要留下這筆調整：未結算分支的部分退款會讓注單留在 BET，
                // 不會翻成 REFUND 擋住重送，重送只剩 corrections 這一道可比對
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::REFUND, $request);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                $betRecord->save();

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'refund_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/debit ─────────────────────────────────────────────────────

    public function debit(Request $request): JsonResponse
    {
        $betRecord = $this->resolveByBetId((string) $request->input('bet_no'), [
            BetRecordStatusEnum::PAYOUT,
            BetRecordStatusEnum::ENDROUND,
            BetRecordStatusEnum::BETPAYOUT,
        ]);
        if (!$betRecord || !$this->ipAllowed() || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::RECORD_NOT_FOUND);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request, $betRecord) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                // debit 的 amount 依 RG SW 文件規定恆為負值，直接代表餘額增減量（跟 credit 同一套 numAdd 公式）
                $amount = Common::numDiv((string) $request->input('amount'), $rate);

                // RG SW 的 order_sn 對 debit/credit 一律等於 bet_no，跟原始結算的 reference_no 撞號，
                // 故不新增記錄，改為直接更新原注單（遊戲商確認：結算 payout_time 不變，只有 win/win_lose 被修正）
                $betRecord = RgswBetRecord::whereKey($betRecord->id)->lockForUpdate()->first();

                // 判重送必須排在餘額檢查之前：第一次扣款已經讓餘額變小，重送時往往再也扣不動，
                // 守衛排在後面就根本問不到，三方會收到「餘額不足」而不是「這筆我處理過了」
                if ($this->isDuplicateCorrection($betRecord, CorrectionTypeEnum::DEBIT, $request)) {
                    return $this->duplicateCorrectionResponse(CorrectionTypeEnum::DEBIT, $betRecord, $walletDto->getTotalBalance(), $amount);
                }

                if (Common::numCompare(Common::numAdd($walletDto->getTotalBalance(), $amount, self::AMOUNT_SCALE), '0', self::AMOUNT_SCALE) < 0) {
                    return $this->errorResponse(StatusCodeEnum::INSUFFICIENT_BALANCE);
                }

                $beforeRecord = clone $betRecord;

                $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::DEBIT, $request);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                $betRecord->save();

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'debit_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/credit ────────────────────────────────────────────────────

    public function credit(Request $request): JsonResponse
    {
        $betRecord = $this->resolveByBetId((string) $request->input('bet_no'), [
            BetRecordStatusEnum::PAYOUT,
            BetRecordStatusEnum::ENDROUND,
            BetRecordStatusEnum::BETPAYOUT,
        ]);
        if (!$betRecord || !$this->ipAllowed() || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::RECORD_NOT_FOUND);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request, $betRecord) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $amount = Common::numDiv((string) $request->input('amount'), $rate);

                // RG SW 的 order_sn 對 debit/credit 一律等於 bet_no，跟原始結算的 reference_no 撞號，
                // 故不新增記錄，改為直接更新原注單（遊戲商確認：結算 payout_time 不變，只有 win/win_lose 被修正）
                $betRecord = RgswBetRecord::whereKey($betRecord->id)->lockForUpdate()->first();

                if ($this->isDuplicateCorrection($betRecord, CorrectionTypeEnum::CREDIT, $request)) {
                    return $this->duplicateCorrectionResponse(CorrectionTypeEnum::CREDIT, $betRecord, $walletDto->getTotalBalance(), $amount);
                }

                $beforeRecord = clone $betRecord;

                $betRecord->win = Common::numAdd($betRecord->win, $amount, 4);
                $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::CREDIT, $request);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                $betRecord->save();

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'credit_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── POST /game/sumBet ────────────────────────────────────────────────────

    public function sumBet(Request $request): JsonResponse
    {
        $betRecord = $this->resolveByBetId((string) $request->input('bet_no'), [
            BetRecordStatusEnum::PAYOUT,
            BetRecordStatusEnum::ENDROUND,
            BetRecordStatusEnum::BETPAYOUT,
        ]);
        if (!$betRecord || !$this->ipAllowed() || !$this->verifyWtoken($request)) {
            return $this->errorResponse(StatusCodeEnum::RECORD_NOT_FOUND);
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(StatusCodeEnum::ACCOUNT_ERROR);
        }

        try {
            return DB::transaction(function () use ($request, $betRecord) {
                $rate = $this->vendorInfo->rate;
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $amount = Common::numDiv((string) $request->input('amount'), $rate);

                // RG SW 的 order_sn 對 sumBet 一律等於 bet_no，跟原始結算的 reference_no 撞號，
                // 故不新增記錄，改為將累加投注疊加進原注單（遊戲商確認：payout_time 不變，只有 updated_at 會異動）
                $betRecord = RgswBetRecord::whereKey($betRecord->id)->lockForUpdate()->first();

                // 判重送必須排在餘額檢查之前，理由同 debit()
                if ($this->isDuplicateCorrection($betRecord, CorrectionTypeEnum::SUM_BET, $request)) {
                    return $this->duplicateCorrectionResponse(CorrectionTypeEnum::SUM_BET, $betRecord, $walletDto->getTotalBalance(), $amount);
                }

                if (Common::numCompare($walletDto->getTotalBalance(), $amount, self::AMOUNT_SCALE) < 0) {
                    return $this->errorResponse(StatusCodeEnum::INSUFFICIENT_BALANCE);
                }

                $beforeRecord = clone $betRecord;

                $betRecord->bet = Common::numAdd($betRecord->bet, $amount, 4);
                $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $amount, 4);
                $betRecord->win_lose = Common::numSub($betRecord->win_lose, $amount, 4);
                $betRecord->info = $this->appendCorrectionToInfo($betRecord->info, CorrectionTypeEnum::SUM_BET, $request);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $betRecord);

                $betRecord->save();

                (new TotalBetRecordService(
                    vendorId: $this->vendorId,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse(
                    (float) Common::numMul($walletDto->getTotalBalance(), $rate, 4)
                );
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->errorResponse(StatusCodeEnum::FLOW_NUMBER_IS_EXIST);
            }
            Log::error($this->actionPrefix . 'sumBet_db_error', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return $this->errorResponse(StatusCodeEnum::TRANSACTION_FAILED);
        }
    }

    // ─── private helpers ──────────────────────────────────────────────────────

    private function ipAllowed(): bool
    {
        return Common::verifyIPWhitelistByVendorId($this->vendorId);
    }

    private function resolveByUsername(string $username): bool
    {
        if ($username === '') {
            return false;
        }

        $this->member = $this->getMemberByThirdPartyUsername($username);
        if (!$this->member) {
            return false;
        }

        $this->vendorInfo = $this->getVendorInfo('', $this->account);

        return $this->vendorInfo !== null;
    }

    /**
     * bet_id 反查注單，呼叫端依自身語意傳入允許比對的 status 清單。
     * RG SW 的 order_sn（refund/debit/credit/sumBet 共用的交易編號）與下注時的 bet_id 同值，
     * 故 refund 查詢也走這支，不另外用 reference_no 查。
     *
     * @param  BetRecordStatusEnum[]  $statuses
     */
    private function resolveByBetId(string $betId, array $statuses): ?RgswBetRecord
    {
        $record = RgswBetRecord::where('bet_id', $betId)
            ->whereIn('status', array_map(fn (BetRecordStatusEnum $status) => $status->value, $statuses))
            ->latest('id')
            ->first();

        if (!$record || !$this->resolveByUsername($record->username)) {
            return null;
        }

        return $record;
    }

    /** payout/endRound/debit/credit/sumBet/refund 直接更新原注單時，把這次請求併入 info.corrections，避免覆蓋 bet() 原始 info，供之後回查歷次異動 */
    private function appendCorrectionToInfo(?string $info, CorrectionTypeEnum $type, Request $request): string
    {
        $decoded = json_decode((string) $info, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded['corrections'][] = [
            'type' => $type->value,
            'request' => $request->all(),
            'applied_at' => now()->toDateTimeString(),
        ];

        return json_encode($decoded);
    }

    /**
     * 這次的調整是不是遊戲商重送？
     *
     * RG 的調整類請求沒有交易序號可以當冪等鍵。實測 TPower（`rgsw_bet_records` 共 180,970 列，
     * 六個站點中唯一有 RG 流量者）：credit 的 payload 只有 amount／bet_no／order_sn／username，
     * 連欄位最多的 payout 也沒有序號，而且 order_sn 恆等於 bet_no。所以只能用「同一局、同一種調整、
     * 同一個金額、而且剛剛才套用過」來認；時間窗與誤擋的取捨見 docs/integrations/vendors/RGSW/integration.md。
     *
     * 比對對象是**已經被 lockForUpdate() 鎖住的那一列**自己的 info.corrections，屬於鎖定讀（當前讀），
     * 所以不會踩到 SpadegamingSW（target/op_tickets#3098）那種「拿到會員鎖之後的一般讀仍看到舊快照」的坑；
     * 也因為不另外查一張表，不需要新索引、不會多出 gap lock。
     */
    private function isDuplicateCorrection(RgswBetRecord $betRecord, CorrectionTypeEnum $type, Request $request): bool
    {
        $identityKeys = $type->identityKeys();
        if ($identityKeys === null) {
            return false;
        }

        $decoded = json_decode((string) $betRecord->info, true);
        $corrections = is_array($decoded) ? ($decoded['corrections'] ?? null) : null;

        if (!is_array($corrections)) {
            return false;
        }

        $earliest = now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS);

        foreach ($corrections as $correction) {
            if (!is_array($correction) || ($correction['type'] ?? null) !== $type->value) {
                continue;
            }

            if (!$this->isSameAdjustment($correction['request'] ?? null, $identityKeys, $request)) {
                continue;
            }

            $appliedAt = $correction['applied_at'] ?? null;
            if (!is_string($appliedAt) || $appliedAt === '') {
                continue;
            }

            if (Carbon::parse($appliedAt)->greaterThanOrEqualTo($earliest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 已經記下來的那一筆調整，和這次進來的請求是同一筆嗎？
     *
     * 只比對「決定動到哪一局」的欄位加上金額，不比整包 payload。整包比對看起來更嚴格，
     * 實際上更危險：RG 哪天在請求裡多帶一個會變動的欄位（生產上的 payout 請求就帶了每筆都不同的
     * payout_time），比對就永遠不會相等，守衛靜默失效、重複入帳，而且不留任何訊號。
     *
     * 金額用數值比對而非字串相等：`"3.0"` 與 `"3.0000"` 是同一筆金額，判成兩筆會讓守衛失效。
     *
     * @param  string[]  $identityKeys
     */
    private function isSameAdjustment(mixed $recorded, array $identityKeys, Request $request): bool
    {
        if (!is_array($recorded)) {
            return false;
        }

        foreach ($identityKeys as $key) {
            $recordedValue = $recorded[$key] ?? null;

            if (!is_scalar($recordedValue) || (string) $recordedValue !== (string) $request->input($key, '')) {
                return false;
            }
        }

        $recordedAmount = $recorded['amount'] ?? null;
        $currentAmount = $request->input('amount');

        if (!is_numeric($recordedAmount) || !is_numeric($currentAmount)) {
            return false;
        }

        return Common::numCompare((string) $recordedAmount, (string) $currentAmount, self::AMOUNT_SCALE) === 0;
    }

    /**
     * 判定為重送時的回應：錢包一毛不動，直接回目前餘額的成功回應。
     *
     * RG 沒有「這筆我處理過了」的專屬狀態碼，回錯誤碼等於要三方自己決定要不要再送一次，
     * 而 repo 內沒有 RGSW 文件、三方也還沒回覆重送策略，那條路的行為是未知的；
     * 回成功則是三方看得懂、而且不會再重送的最保守選擇。
     *
     * 誤擋（把合法的第二筆同額調整當成重送）在線上必須看得見，所以一律留下 warning，
     * 而且帶齊「少付了誰、少付多少」——事故當下要能直接從 log 算出影響金額，不必回頭撈注單。
     */
    private function duplicateCorrectionResponse(
        CorrectionTypeEnum $type,
        RgswBetRecord $betRecord,
        string $balance,
        string $amount
    ): JsonResponse {
        Log::warning($this->actionPrefix . 'duplicate_correction_skipped', [
            'type' => $type->value,
            'bet_id' => $betRecord->bet_id,
            'member_id' => $betRecord->member_id,
            'status' => $betRecord->status->name,
            'skipped_amount' => $amount,
            'window_seconds' => self::DUPLICATE_WINDOW_SECONDS,
        ]);

        return $this->successResponse(
            (float) Common::numMul($balance, $this->vendorInfo->rate, 4)
        );
    }

    /** RG 傳入時間為 UTC-4，轉換成 UTC+0 後存入 DB */
    private function parseVendorTime(mixed $value): string
    {
        return Carbon::parse((string) $value, '-04:00')->utc()->toDateTimeString();
    }

    private function verifyWtoken(Request $request): bool
    {
        $wtoken = [REDACTED] $request->header('wtoken', '');

        return $wtoken !== '' && $wtoken === ($this->vendorInfo->info['token'] ?? '');
    }

    private function successResponse(float $balance): JsonResponse
    {
        return response()->json([
            'status' => ['code' => StatusCodeEnum::SUCCESS->value, 'message' => 'Success'],
            'data' => ['balance' => $balance],
        ]);
    }

    protected function errorResponse(StatusCodeEnum $status, string $message = ''): JsonResponse
    {
        Log::error($this->actionPrefix . 'error_' . $status->value, [
            'message' => $message ?: $status->name,
        ]);

        return response()->json([
            'status' => ['code' => $status->value, 'message' => $message ?: $status->name],
        ]);
    }
}
