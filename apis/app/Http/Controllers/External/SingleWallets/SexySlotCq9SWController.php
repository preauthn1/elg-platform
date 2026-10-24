<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\SexySlotCq9SwAccount;
use App\Vendors\Models\SexySlotCq9SwBetRecord;
use App\Vendors\Models\SexySlotCq9SwDealRecord;
use App\Vendors\SexySlotCq9SW\Datas\VendorInfoDetailData;
use App\Vendors\SexySlotCq9SW\Enums\BetRecordStatusEnum;
use App\Vendors\SexySlotCq9SW\Enums\StatusCodeEnum;
use App\Vendors\SexySlotCq9SW\Enums\VendorGameTypeEnum;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SexySlotCq9SWController extends BaseController
{
    protected int $vendorId = VendorIdEnum::SEXYSLOTCQ9SW->value;

    protected string $accountClass = SexySlotCq9SwAccount::class;

    protected string $betRecordClass = SexySlotCq9SwBetRecord::class;

    protected string $actionPrefix = 'SexySlotCq9SW_';

    private array $requestInfos;

    private VendorInfoDetailData $vendorInfoDetail;

    /**
     * 預處理：log、IP 白名單、request 驗證、驗證 wtoken 並載入 account/vendorInfo
     */
    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $this->logRequest($request, $this->actionPrefix . basename(url()->current()));

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                $requestIp = Common::getIp();

                return response()->json(['error' => "IP {$requestIp} not allow"], 401);
            }

            $this->requestInfos = [
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
            ];

            // 打錯或未實作的 action：不必查帳號，一律回 1002
            if ($method === 'actionError') {
                return $this->actionError();
            }

            // 允許未綁定 member 的 account（checkPlayer/balance 對未註冊帳號需回 balance=0）
            $username = $request->route('account') ?? $request->input('account');

            // account 參數本身缺漏是參數錯誤（1003），不應被下面的帳號查無（1006）蓋過
            if (blank($username)) {
                throw new Exception('The account field is required', StatusCodeEnum::PARAMETER_ERROR->value);
            }

            $this->account = SexySlotCq9SwAccount::query()
                ->with(['member', 'vendorInfo'])
                ->where('username', $username)
                ->first();

            if (!$this->account) {
                // CheckPlayer 查無帳號不是錯誤，回 data:false 讓廠商判斷帳號不存在（此時無 account 可取 vendorInfo，故略過 wtoken 比對，入口已有 IP 白名單把關）
                if ($method === 'checkPlayer') {
                    return $this->response(false);
                }

                throw new Exception('Account not found', StatusCodeEnum::PLAYER_NOT_FOUND->value);
            }

            $this->vendorInfo = $this->account->vendorInfo;
            if (!$this->vendorInfo) {
                throw new Exception('VendorInfo not found', StatusCodeEnum::SERVER_ERROR->value);
            }

            $this->vendorInfoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
            $this->member = $this->account->member; // 允許未綁定 member 的 account

            if ($this->vendorInfoDetail->token !== $request->header('wtoken')) {
                throw new Exception('Invalid request', StatusCodeEnum::ACTION_ERROR->value);
            }

            return parent::callAction($method, $parameters);
        } catch (QueryException $e) {
            $errorCode = StatusCodeEnum::SERVER_ERROR->value;
            // 1062 = 併發撞同一個 mtcode：回 1100 讓廠商重送，重送時會走到冪等分支回前次餘額
            $message = ($e->errorInfo[1] ?? null) === 1062 ? 'Duplicate mtcode' : $e->getMessage();
        } catch (ValidationException $e) {
            $errorCode = StatusCodeEnum::PARAMETER_ERROR->value;
            $message = $e->getMessage();
        } catch (Throwable $e) {
            $errorCode = ($e instanceof Exception && $e->getCode() > 0) ? $e->getCode() : StatusCodeEnum::SERVER_ERROR->value;
            $message = $e->getMessage();
        }

        // 廠商未定義的錯誤碼（如其他層拋出的 HTTP code）一律歸 1100
        $statusCode = StatusCodeEnum::tryFrom($errorCode) ?? StatusCodeEnum::SERVER_ERROR;

        Log::error("SexySlotCq9SW_error_{$statusCode->value}", [
            'requestData' => $this->requestInfos,
            'responseData' => ['code' => $statusCode->value, 'msg' => $message],
        ]);

        return $this->response(null, $statusCode, $message);
    }

    public function checkPlayer()
    {
        return $this->response(true);
    }

    /**
     * 打錯或未實作的 action（catch-all route）：callAction() 前置處理會略過帳號驗證直接呼叫這裡
     */
    public function actionError(): JsonResponse
    {
        return $this->response(null, StatusCodeEnum::ACTION_ERROR, 'Action error');
    }

    public function balance()
    {
        if ($this->account->status == 0) {
            throw new Exception('Account is disabled', StatusCodeEnum::ACTION_ERROR->value);
        }

        // 起始狀態，還沒綁定 member 時，balance 為 0
        if (empty($this->member)) {
            return $this->successResponse([
                'currency' => $this->vendorInfo->currency,
                'balance' => 0,
                'datetime' => $this->getDateTime(),
            ]);
        }

        if (!BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorInfo->vendor_id)) {
            throw new Exception('Account could not bet yet', StatusCodeEnum::ACTION_ERROR->value);
        }

        $walletsDTO = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo);

        return $this->successResponse([
            'currency' => $this->vendorInfo->currency,
            'balance' => $walletsDTO->getTotalBalance(),
            'datetime' => $this->getDateTime(),
        ]);
    }

    /**
     * Bet/Rollout/TakeAll/Debit 共用骨架：鎖 wallet、查 mtcode 冪等（已處理過直接回傳前次結果，不再增減）。
     *
     * - Bet/Rollout/TakeAll：累加這個 round 的下注金額（TakeAll 的 amount 直接取當下錢包全額），查無 betRecord 可新建。
     * - Debit：針對已結算訂單（ENDROUND/ROLLIN/DEBIT/CREDIT）扣款，只調整 win_lose，查無則拋錯、不新建。
     */
    private function processDeal(Request $request, BetRecordStatusEnum $type): array
    {
        return DB::transaction(function () use ($type, $request) {
            // 先鎖 member/wallet：併發的相同 mtcode 請求會卡在這裡，等前一個 commit 後才會看到下面的 existingDeal
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            // 冪等：mtcode 已存在代表這筆處理過，直接回傳前次成功的結果，不再增減
            $existingDeal = SexySlotCq9SwDealRecord::where('mtcode', $request->input('mtcode'))->first();
            if ($existingDeal) {
                $result = [
                    'balance' => $existingDeal->balance,
                    'currency' => $this->vendorInfo->currency,
                    'datetime' => $this->getDateTime(),
                ];
                if ($type === BetRecordStatusEnum::TAKEALL) {
                    $result['amount'] = $existingDeal->amount;
                }

                return $result;
            }

            $amount = ($type === BetRecordStatusEnum::TAKEALL)
                ? $walletsDTO->getTotalBalance()
                : Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

            if (($type === BetRecordStatusEnum::BET || $type === BetRecordStatusEnum::ROLLOUT) && Common::numCompare($amount, 0) <= 0) {
                throw new Exception('Bet amount must be greater than 0', StatusCodeEnum::PARAMETER_ERROR->value);
            }

            // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
            // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
            // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
            // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
            $betRecord = SexySlotCq9SwBetRecord::where('bet_id', $request->input('roundid'))->first();
            $beforeBetRecord = $betRecord ? clone $betRecord : null;

            if ($type === BetRecordStatusEnum::DEBIT) {
                // Debit 針對已結算訂單（ENDROUND/ROLLIN/DEBIT/CREDIT）扣款，只調整 win_lose，查無或注單未結算則拋錯、不新建
                if (!$betRecord || !in_array($betRecord->type, [BetRecordStatusEnum::ENDROUND, BetRecordStatusEnum::ROLLIN, BetRecordStatusEnum::DEBIT, BetRecordStatusEnum::CREDIT], true)) {
                    throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
                }

                // Debit 是扣款：先確認餘額夠扣，避免扣成負數
                if (Common::numCompare($walletsDTO->getTotalBalance(), $amount) < 0) {
                    throw new Exception('Debit amount is greater than wallet balance', StatusCodeEnum::INSUFFICIENT_BALANCE->value);
                }

                $betRecord->win_lose = Common::numSub($betRecord->win_lose, $amount, 4);
                $betRecord->payout_time = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();
                $betRecord->type = $type;
            } else {
                // Bet/Rollout/TakeAll：累加這個 round 的下注金額，查無則新建
                if (!$betRecord) {
                    $betRecord = new SexySlotCq9SwBetRecord([
                        'username' => $this->account->username,
                        'bet_id' => $request->input('roundid'),
                        'game_code' => $request->input('gamecode'),
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win_lose' => '0',
                        'rolling_chip' => '0',
                        'bonus' => '0',
                        'jackpot' => '0',
                        'bet_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                        'payout_time' => '1970-01-01 00:00:01',
                        'type' => $type,
                    ]);
                }

                $betRecord->bet = Common::numAdd($betRecord->bet, $amount, 4);
                $betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $amount, 4);
                $betRecord->win_lose = Common::numMul($betRecord->bet, -1, 4);
                $betRecord->type = $type;

                if (Common::numCompare($walletsDTO->getTotalBalance(), $amount) < 0) {
                    throw new Exception('Wallet balance not enough', StatusCodeEnum::INSUFFICIENT_BALANCE->value);
                }
            }

            $dealRecord = SexySlotCq9SwDealRecord::create([
                'bet_id' => $request->input('roundid'),
                'mtcode' => $request->input('mtcode'),
                'amount' => $amount,
                'username' => $this->account->username,
                'game_hall' => $request->input('gamehall'),
                'game_code' => $request->input('gamecode'),
                'type' => $type,
                'event_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'info' => $request->all(),
            ]);

            $transactionLogId = $this->changeBalance($this->member, $walletsDTO, $beforeBetRecord, $betRecord);

            // Rollout/TakeAll 只是轉入遊戲商、尚未結算（結算在 rollin()），不寫入 total_bet_records
            if ($type !== BetRecordStatusEnum::ROLLOUT && $type !== BetRecordStatusEnum::TAKEALL) {
                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletsDTO->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);
            }

            $dealRecord->update(['balance' => $walletsDTO->getTotalBalance()]);

            $result = [
                'balance' => $walletsDTO->getTotalBalance(),
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
            if ($type === BetRecordStatusEnum::TAKEALL) {
                $result['amount'] = $amount;
            }

            return $result;
        });
    }

    /**
     * Bet：投注
     */
    public function bet(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
            'session' => 'string|nullable|max:255',
            'platform' => 'string|nullable|max:255',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $request->input('gamecode'))) {
            throw new Exception('Account could not bet yet', StatusCodeEnum::ACTION_ERROR->value);
        }

        return $this->successResponse($this->processDeal($request, BetRecordStatusEnum::BET));
    }

    /**
     * Rollout：轉入遊戲商(捕魚或棋牌)
     */
    public function rollout(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
            'session' => 'string|nullable|max:255',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $request->input('gamecode'))) {
            throw new Exception('Account could not bet yet', StatusCodeEnum::ACTION_ERROR->value);
        }

        return $this->successResponse($this->processDeal($request, BetRecordStatusEnum::ROLLOUT));
    }

    /**
     * TakeAll：轉出玩家『全部』餘額至遊戲（roundid 與 Rollin 共用，rollin() 已同時接受 ROLLOUT/TAKEALL 兩種 type）。
     * 與 bet/rollout 相同的 round 累計寫法，只是 amount 不吃 request，直接用目前錢包全額出清（執行後餘額必為 0）。
     */
    public function takeall(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'mtcode' => 'required|string|max:70',
            'session' => 'string|nullable|max:255',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $request->input('gamecode'))) {
            throw new Exception('Account could not bet yet', StatusCodeEnum::ACTION_ERROR->value);
        }

        return $this->successResponse($this->processDeal($request, BetRecordStatusEnum::TAKEALL));
    }

    /**
     * Debit：針對已結算的訂單做扣款
     */
    public function debit(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        return $this->successResponse($this->processDeal($request, BetRecordStatusEnum::DEBIT));
    }

    /**
     * Refund：mtcode 是「原 Bet 的 mtcode」，查既有 deal_record（不新增），
     * 用它自己記錄的 amount 反向沖銷 round 彙總，changeBalance() 會照 round 目前的禮碼餘額退款。
     */
    public function refund(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'mtcode' => 'required|string|max:70',
        ]);

        $result = DB::transaction(function () use ($request) {
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $dealRecord = SexySlotCq9SwDealRecord::where('mtcode', $request->input('mtcode'))
                ->lockForUpdate()
                ->first();

            if (!$dealRecord) {
                throw new Exception("Game mtcode {$request->input('mtcode')} not found", StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            if ($dealRecord->is_refund) {
                // 冪等：resend 已退款過的 mtcode，回「前次退款當下」的餘額，不重複沖銷也不回已被其他交易改動過的現值
                return [
                    'balance' => $dealRecord->refund_balance ?? $walletsDTO->getTotalBalance(),
                    'currency' => $this->vendorInfo->currency,
                    'datetime' => $this->getDateTime(),
                ];
            }

            $betRecord = SexySlotCq9SwBetRecord::where('bet_id', $dealRecord->bet_id)->lockForUpdate()->first();

            if (!$betRecord) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $beforeBetRecord = clone $betRecord;

            $betRecord->bet = Common::numSub($betRecord->bet, $dealRecord->amount, 4);
            $betRecord->valid_bet = Common::numSub($betRecord->valid_bet, $dealRecord->amount, 4);
            $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $dealRecord->amount, 4);
            $betRecord->type = BetRecordStatusEnum::REFUND;

            $transactionLogId = $this->changeBalance($this->member, $walletsDTO, $beforeBetRecord, $betRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletsDTO->isMultipleWallet,
            ))->create($betRecord, $transactionLogId);

            $dealRecord->update([
                'is_refund' => true,
                'refund_balance' => $walletsDTO->getTotalBalance(),
            ]);

            return [
                'balance' => $walletsDTO->getTotalBalance(),
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
        });

        return $this->successResponse($result);
    }

    /**
     * EndRound：data[] 每筆各自帶 mtcode，逐筆 insert-first 冪等、逐筆累加派彩到 round 彙總。
     * 不需要另外查「這局是否已結算」——單筆 mtcode 重複會直接撞 1062。
     */
    public function endround(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'data' => 'required|json', // JSON 字串，內容於下方解開後另驗（data.* 規則對字串無效）
            'createTime' => 'required|string|max:35',
            'freegame' => 'nullable|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
            'luckydraw' => 'nullable|numeric|min:0',
            'jackpot' => 'nullable|numeric|min:0',
            'jackpotcontribution' => 'nullable|numeric|min:0',
            'freeticket' => 'nullable|in:true,false,1,0',
        ]);

        // data 是 JSON 字串，validate() 的 data.* 規則對字串不生效，解開後另驗一次（欄位缺漏／金額為負 → 1003）
        $data = json_decode((string) $request->input('data'), true);

        Validator::make(['data' => $data], [
            'data' => 'required|array|min:1',
            'data.*' => 'array',
            'data.*.mtcode' => 'required|string|max:70',
            'data.*.amount' => 'required|numeric|min:0',
            'data.*.eventtime' => 'required|string|max:35',
            'data.*.validbet' => 'required|numeric|min:0',
        ])->validate();

        $this->assertRfc3339((string) $request->input('createTime'), ...array_map('strval', array_column($data, 'eventtime')));

        $result = DB::transaction(function () use ($request, $data) {
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            // 整批都是 resend 時要回「前次結算當下」的餘額（最後一筆 mtcode 的變動後餘額），
            // 不能回已被其他交易改動過的現值；只要有任何一筆是新的，現值才是這批的正確結果
            $resentBalance = $walletsDTO->getTotalBalance();
            $hasNewDeal = false;

            foreach ($data as $row) {
                // 冪等：這筆 mtcode 處理過就跳過，繼續處理同批次裡其他還沒處理過的筆
                if ($processedDeal = SexySlotCq9SwDealRecord::where('mtcode', $row['mtcode'])->first()) {
                    $resentBalance = $processedDeal->balance;

                    continue;
                }

                $hasNewDeal = true;

                $amount = Common::numDiv($row['amount'], $this->vendorInfo->rate);

                $dealRecord = SexySlotCq9SwDealRecord::create([
                    'bet_id' => $request->input('roundid'),
                    'mtcode' => $row['mtcode'],
                    'amount' => $amount,
                    'username' => $this->account->username,
                    'game_hall' => $request->input('gamehall'),
                    'game_code' => $request->input('gamecode'),
                    'type' => BetRecordStatusEnum::ENDROUND,
                    'event_time' => Carbon::parse($row['eventtime'])->toSystemTime()->toDateTimeString(),
                    'info' => $request->all(),
                ]);

                // ⚠️ 不可加 lockForUpdate()：對不存在的 bet_id 做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 同一筆注單的累加不靠這把鎖：bet_id 屬單一玩家，上方 getMemberLockedWallets() 的會員列鎖
                // 已序列化同會員請求；跨玩家撞號由 bet_id UNIQUE 擋下（1062 由 catch 處理）。
                $betRecord = SexySlotCq9SwBetRecord::where('bet_id', $request->input('roundid'))->first();

                // freeticket：贈品局沒有對應的 Bet，允許無 Bet 紀錄直接建局結算
                if (!$betRecord && !$request->boolean('freeticket')) {
                    throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
                }

                $beforeBetRecord = $betRecord ? clone $betRecord : null;

                if (!$betRecord) {
                    $betRecord = new SexySlotCq9SwBetRecord([
                        'username' => $this->account->username,
                        'bet_id' => $request->input('roundid'),
                        'game_code' => $request->input('gamecode'),
                        'bet' => '0',
                        'valid_bet' => '0',
                        'win_lose' => '0',
                        'rolling_chip' => '0',
                        'bonus' => '0',
                        'jackpot' => '0',
                        'bet_time' => Carbon::parse($row['eventtime'])->toSystemTime()->toDateTimeString(),
                        'payout_time' => '1970-01-01 00:00:01',
                        'type' => BetRecordStatusEnum::ENDROUND,
                    ]);
                }

                $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
                $betRecord->payout_time = Carbon::parse($row['eventtime'])->toSystemTime()->toDateTimeString();
                $betRecord->type = BetRecordStatusEnum::ENDROUND;

                $transactionLogId = $this->changeBalance($this->member, $walletsDTO, $beforeBetRecord, $betRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletsDTO->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                $dealRecord->update(['balance' => $walletsDTO->getTotalBalance()]);
            }

            return [
                'balance' => $hasNewDeal ? $walletsDTO->getTotalBalance() : $resentBalance,
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
        });

        return $this->successResponse($result);
    }

    /**
     * Rollin：結算 Rollout/TakeAll
     */
    public function rollin(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',

            /**
             * ※老虎機/街機/魚機有效投注為0，請直接使用參數bet
             * ※此欄位值用於牌桌/真人及體彩類遊戲
             */
            'validbet' => 'required|numeric|min:0',

            /** ※因應牌桌/真人遊戲的規則，此欄位的值有可能為0 */
            'bet' => 'required|numeric|min:0',

            /** ※因應牌桌/真人遊戲的規則，此欄位必須能接受負值 */
            'win' => 'required|numeric',

            /** ※此欄位為牌桌遊戲使用（文件為選填，slot/fish 不會帶） */
            'roomfee' => 'nullable|numeric|min:0',

            /** ※金額不得為負值 */
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
            'createTime' => 'required|string|max:35',
            'rake' => 'required|numeric|min:0',
            'gametype' => 'required|string|max:36',
            'tableid' => 'nullable|string|max:36',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'), (string) $request->input('createTime'));

        $result = DB::transaction(function () use ($request) {
            // 先鎖 member/wallet：併發的相同 mtcode 請求會卡在這裡，等前一個 commit 後才會看到下面的 existingDeal
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

            // 冪等：mtcode 已存在代表這筆處理過，直接回傳前次成功的餘額，不再增減
            $existingDeal = SexySlotCq9SwDealRecord::where('mtcode', $request->input('mtcode'))->first();
            if ($existingDeal) {
                return [
                    'balance' => $existingDeal->balance,
                    'currency' => $this->vendorInfo->currency,
                    'datetime' => $this->getDateTime(),
                ];
            }

            $dealRecord = SexySlotCq9SwDealRecord::create([
                'bet_id' => $request->input('roundid'),
                'mtcode' => $request->input('mtcode'),
                'amount' => $amount,
                'username' => $this->account->username,
                'game_hall' => $request->input('gamehall'),
                'game_code' => $request->input('gamecode'),
                'type' => BetRecordStatusEnum::ROLLIN,
                'event_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'info' => $request->all(),
            ]);

            $betRecord = SexySlotCq9SwBetRecord::where('bet_id', $request->input('roundid'))
                ->whereIn('type', [BetRecordStatusEnum::ROLLOUT, BetRecordStatusEnum::TAKEALL])
                ->lockForUpdate()
                ->first();

            if (!$betRecord) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $beforeBetRecord = clone $betRecord;

            // 淨輸贏 = 這次轉回的 amount − 當時 Rollout/TakeAll 轉出的 bet
            $betRecord->win_lose = Common::numSub($amount, $betRecord->bet, 4);
            $betRecord->bet = Common::numDiv($request->input('bet'), $this->vendorInfo->rate);
            $betRecord->valid_bet = (VendorGameTypeEnum::tryFrom((string) $request->input('gametype'))?->usesBetAsValidBet() ?? false)
                ? $betRecord->bet
                : Common::numDiv($request->input('validbet'), $this->vendorInfo->rate);
            $betRecord->payout_time = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();
            $betRecord->type = BetRecordStatusEnum::ROLLIN;

            $transactionLogId = $this->changeBalance($this->member, $walletsDTO, $beforeBetRecord, $betRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletsDTO->isMultipleWallet,
            ))->create($betRecord, $transactionLogId);

            $dealRecord->update(['balance' => $walletsDTO->getTotalBalance()]);

            return [
                'balance' => $walletsDTO->getTotalBalance(),
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
        });

        return $this->successResponse($result);
    }

    /**
     * Credit：針對已結算的訂單做補款
     */
    public function credit(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        $result = DB::transaction(function () use ($request) {
            // 先鎖 member/wallet：併發的相同 mtcode 請求會卡在這裡，等前一個 commit 後才會看到下面的 existingDeal
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

            // 冪等：mtcode 已存在代表這筆處理過，直接回傳前次成功的餘額，不再增減
            $existingDeal = SexySlotCq9SwDealRecord::where('mtcode', $request->input('mtcode'))->first();
            if ($existingDeal) {
                return [
                    'balance' => $existingDeal->balance,
                    'currency' => $this->vendorInfo->currency,
                    'datetime' => $this->getDateTime(),
                ];
            }

            $dealRecord = SexySlotCq9SwDealRecord::create([
                'bet_id' => $request->input('roundid'),
                'mtcode' => $request->input('mtcode'),
                'amount' => $amount,
                'username' => $this->account->username,
                'game_hall' => $request->input('gamehall'),
                'game_code' => $request->input('gamecode'),
                'type' => BetRecordStatusEnum::CREDIT,
                'event_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'info' => $request->all(),
            ]);

            $betRecord = SexySlotCq9SwBetRecord::where('bet_id', $request->input('roundid'))
                ->whereIn('type', [BetRecordStatusEnum::ENDROUND, BetRecordStatusEnum::ROLLIN, BetRecordStatusEnum::DEBIT, BetRecordStatusEnum::CREDIT])
                ->lockForUpdate()
                ->first();

            if (!$betRecord) {
                throw new Exception('Bet record not found', StatusCodeEnum::RECORD_NOT_FOUND->value);
            }

            $beforeBetRecord = clone $betRecord;

            // Credit 是入帳，沒有餘額不足的問題，不需要事先檢查
            $betRecord->win_lose = Common::numAdd($betRecord->win_lose, $amount, 4);
            $betRecord->payout_time = Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString();
            $betRecord->type = BetRecordStatusEnum::CREDIT;

            $transactionLogId = $this->changeBalance($this->member, $walletsDTO, $beforeBetRecord, $betRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletsDTO->isMultipleWallet,
            ))->create($betRecord, $transactionLogId);

            $dealRecord->update(['balance' => $walletsDTO->getTotalBalance()]);

            return [
                'balance' => $walletsDTO->getTotalBalance(),
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
        });

        return $this->successResponse($result);
    }

    /**
     * Payoff：活動獎勵直接派發給玩家，無 roundid 概念——bet_id 借用 mtcode 當唯一鍵，
     * 視為一筆獨立的 win_lose=amount 事件寫入 bet_records（供報表/對帳），不與任何注單掛勾。
     */
    public function payoff(Request $request)
    {
        $request->validate([
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
            'promoid' => 'nullable|string|max:70',
            'remark' => 'nullable|string',
        ]);

        $this->assertRfc3339((string) $request->input('eventTime'));

        $result = DB::transaction(function () use ($request) {
            // 先鎖 member/wallet：併發的相同 mtcode 請求會卡在這裡，等前一個 commit 後才會看到下面的 existingDeal
            $walletsDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $amount = Common::numDiv($request->input('amount'), $this->vendorInfo->rate);

            // 冪等：mtcode 已存在代表這筆處理過，直接回傳前次成功的餘額，不再增減
            $existingDeal = SexySlotCq9SwDealRecord::where('mtcode', $request->input('mtcode'))->first();
            if ($existingDeal) {
                return [
                    'balance' => $existingDeal->balance,
                    'currency' => $this->vendorInfo->currency,
                    'datetime' => $this->getDateTime(),
                ];
            }

            $dealRecord = SexySlotCq9SwDealRecord::create([
                'bet_id' => $request->input('mtcode'), // Payoff 沒有 roundid，借用 mtcode 當唯一鍵
                'mtcode' => $request->input('mtcode'),
                'amount' => $amount,
                'username' => $this->account->username,
                'game_hall' => '',
                'game_code' => (string) $request->input('promoid', ''),
                'type' => BetRecordStatusEnum::PAYOFF,
                'event_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'info' => $request->all(),
            ]);

            $betRecord = new SexySlotCq9SwBetRecord([
                'username' => $this->account->username,
                'bet_id' => $request->input('mtcode'),
                'game_code' => (string) $request->input('promoid', ''),
                'bet' => '0',
                'valid_bet' => '0',
                'win_lose' => '0',
                'rolling_chip' => '0',
                'bonus' => $amount,
                'jackpot' => '0',
                'bet_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'payout_time' => Carbon::parse($request->input('eventTime'))->toSystemTime()->toDateTimeString(),
                'type' => BetRecordStatusEnum::PAYOFF,
            ]);

            $transactionLogId = $this->changeBalance($this->member, $walletsDTO, null, $betRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletsDTO->isMultipleWallet,
            ))->create($betRecord, $transactionLogId);

            $dealRecord->update(['balance' => $walletsDTO->getTotalBalance()]);

            return [
                'balance' => $walletsDTO->getTotalBalance(),
                'currency' => $this->vendorInfo->currency,
                'datetime' => $this->getDateTime(),
            ];
        });

        return $this->successResponse($result);
    }

    private function getDateTime(): string
    {
        return now()->setTimezone(new \DateTimeZone('-04:00'))->format('c');
    }

    /**
     * RFC3339 時間格式驗證（必含時區偏移，毫秒位數不限）；不符回 1004
     */
    private function assertRfc3339(string ...$times): void
    {
        foreach ($times as $time) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $time)) {
                throw new Exception("Invalid time format: {$time}", StatusCodeEnum::TIME_FORMAT_ERROR->value);
            }
        }
    }

    private function successResponse(array $result): JsonResponse
    {
        $data = [
            'balance' => (float) Common::numMul($result['balance'], $this->vendorInfo->rate, 4),
            'currency' => $result['currency'],
        ];

        // TakeAll 額外回傳 amount（轉出前的餘額），其餘動作沒有這個欄位
        if (array_key_exists('amount', $result)) {
            $data['amount'] = (float) Common::numMul($result['amount'], $this->vendorInfo->rate, 4);
        }

        return $this->response($data, datetime: $result['datetime']);
    }

    /**
     * CQ9 Wallet API 回應：HTTP 一律 200，錯誤碼放在 status.code
     */
    private function response(mixed $data, StatusCodeEnum $code = StatusCodeEnum::SUCCESS, string $message = 'Success', ?string $datetime = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'status' => [
                'code' => (string) $code->value,
                'message' => $message,
                'datetime' => $datetime ?? $this->getDateTime(),
            ],
        ]);
    }
}
