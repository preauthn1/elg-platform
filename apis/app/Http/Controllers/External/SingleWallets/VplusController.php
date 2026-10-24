<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Requests\External\VplusSW\BetRequest;
use App\Http\Requests\External\VplusSW\GetBalanceRequest;
use App\Http\Requests\External\VplusSW\SettleRequest;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\VplusswAccount;
use App\Vendors\Models\VplusswBetRecord;
use App\Vendors\VplusSW\Datas\VendorInfoDetailData;
use App\Vendors\VplusSW\Enums\BetRecordStatusEnum;
use App\Vendors\VplusSW\Enums\ErrorCodeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * vplussw 派彩跟投注在每日統計是分開的，所以需要分開各自產生一筆 total_bet_record
 */
class VplusController extends BaseController
{
    protected int $vendorId = VendorIdEnum::VPLUSSW->value;

    protected string $accountClass = VplusswAccount::class;

    protected string $betRecordClass = VplusswBetRecord::class;

    protected string $actionPrefix = 'Vplussw_request_';

    protected VendorInfoDetailData $infoDetail;

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
                return $this->errorResponse(ErrorCodeEnum::VERIFICATION_FAILED, 'IP not in whitelist');
            }

            $this->member = $this->getMemberByThirdPartyUsername($request->input('username'));
            if (!$this->member) {
                return $this->errorResponse(ErrorCodeEnum::USER_NOT_FOUND, 'User not found');
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo(account: $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(ErrorCodeEnum::VERIFICATION_FAILED, 'Can not find corresponding vendor info');
            }
            $this->infoDetail = VendorInfoDetailData::from($this->vendorInfo->info);

            // 驗證簽名
            if ($this->checkSign($request) === false) {
                return $this->errorResponse(ErrorCodeEnum::VERIFICATION_FAILED, 'Sign error');
            }

            $request->merge([
                'datetime' => Carbon::createFromTimestampMs($request->input('timestamp'))->toDateTimeString(),
            ]);

            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);

            // 後置處理：例如包裝 response

            return $response;
        } catch (ValidationException $e) {
            return $this->errorResponse(ErrorCodeEnum::REQUEST_PARAMETER_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            // 統一錯誤處理
            $action = $this->actionPrefix . 'error';
            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 查詢餘額
     */
    public function balance(Request $request)
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(ErrorCodeEnum::USER_NOT_FOUND, 'User not found');
        }
        $input = $request->validate((new GetBalanceRequest)->rules());
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

        return $this->response($balance);
    }

    public function bet(Request $request)
    {
        $input = $request->validate((new BetRequest)->rules());
        if ($request->input('completed')) {
            return $this->handleSettle($request);
        } else {
            return $this->handleBet($request);
        }
    }

    public function settle(Request $request)
    {
        $input = $request->validate((new SettleRequest)->rules());
        if ($request->input('type') === 101) {
            return $this->handleRefund($request);
        } else {
            return $this->handleSettle($request);
        }
    }

    private function handleBet(Request $request)
    {
        $input = $request->validate((new BetRequest)->rules());
        if ($this->account->status == 0) {
            return $this->errorResponse(ErrorCodeEnum::USER_NOT_FOUND, 'User not found');
        }

        $isAbleToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $request->input('gameId'));
        if (!$isAbleToBet) {
            return $this->errorResponse(ErrorCodeEnum::BET_IS_NOT_ALLOWED);
        }
        try {
            DB::beginTransaction();

            // 交易內第一個 SQL 必須是鎖會員列，之後的一般讀才是現值（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 取得投注紀錄
            $gameRoundId = $request->input('gameRoundId');
            $transactionId = $request->input('transactionId');
            $betRecord = VplusswBetRecord::where('bet_id', $transactionId)->first();

            // 投注紀錄已存在
            if ($betRecord) {
                return $this->errorResponse(ErrorCodeEnum::REPEAT_REQUEST, 'transactionId repeat');
            }

            $bet = Common::numDiv($request->input('balance'), $this->vendorInfo->rate);
            $win = '0';
            if (Common::numCompare($bet, 0) < 0) {
                return $this->errorResponse(ErrorCodeEnum::REQUEST_PARAMETER_ERROR, 'Bet amount should be positive');
            }

            if (Common::numCompare($walletDto->getTotalBalance(), $bet) < 0) {
                return $this->errorResponse(ErrorCodeEnum::BET_IS_NOT_ALLOWED, 'Wallet balance not enough');
            }

            $betRecord = new VplusswBetRecord([
                'bet_id' => $transactionId,
                'reference_bet_id' => $gameRoundId,
                'username' => $request->input('username'),
                'game_code' => $request->input('gameId'),
                'status' => BetRecordStatusEnum::BET->value,
                'infos' => '[]',
                'type' => $request->input('type'),
                'bet_time' => $request->input('datetime'),
                'payout_time' => $request->input('datetime'),
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => Common::numSub($win, $bet),
                'rolling_chips' => 0,
            ]);
            // 紀錄請求資料
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $request->all());

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄
            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);
            DB::commit();

            $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

            return $this->response($balance);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    private function handleSettle(Request $request)
    {
        $input = $request->validate((new SettleRequest)->rules());
        try {
            DB::beginTransaction();

            // 交易內第一個 SQL 必須是鎖會員列，之後的一般讀才是現值（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 取得投注紀錄
            $gameRoundId = $request->input('gameRoundId');
            $transactionId = $request->input('transactionId');
            $betRecord = VplusswBetRecord::where('bet_id', $transactionId)->first();

            // 投注紀錄已存在
            if ($betRecord) {
                return $this->errorResponse(ErrorCodeEnum::REPEAT_REQUEST, 'transactionId repeat');
            }

            $bet = '0';
            $win = Common::numDiv($request->input('balance'), $this->vendorInfo->rate);

            $betRecord = new VplusswBetRecord([
                'bet_id' => $transactionId,
                'reference_bet_id' => $gameRoundId,
                'username' => $request->input('username'),
                'game_code' => $request->input('gameId'),
                'status' => BetRecordStatusEnum::SETTLE->value,
                'infos' => '[]',
                'type' => $request->input('type'),
                'bet_time' => $request->input('datetime'),
                'payout_time' => $request->input('datetime'),
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => Common::numSub($win, $bet),
                'rolling_chips' => 0,
            ]);
            // 紀錄請求資料
            $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $request->all());

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $betRecord->save();

            // 寫入全部遊戲紀錄, 如果贏錢才寫入, 輸錢的話三方回傳win=0
            if (Common::numCompare($win, 0) > 0) {
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);
            }
            DB::commit();

            $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

            return $this->response($balance);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    private function handleRefund(Request $request)
    {
        $input = $request->validate((new SettleRequest)->rules());
        try {
            DB::beginTransaction();
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            // 取消投注是以gameRoundId為單位，所以需要查詢多筆投注紀錄
            $gameRoundId = $request->input('gameRoundId');
            // $transactionId = $request->input('transactionId');
            $oldBetRecords = VplusswBetRecord::lockForUpdate()
                ->where('reference_bet_id', $gameRoundId)
                ->get();

            // 可能會有多筆投注紀錄，需要一筆一筆處理取消
            foreach ($oldBetRecords as $oldBetRecord) {
                if ($oldBetRecord->status == BetRecordStatusEnum::REFUND->value) {
                    continue;
                }
                $betRecord = clone $oldBetRecord;
                $betRecord->status = BetRecordStatusEnum::REFUND->value;
                $betRecord->type = $request->input('type');
                $betRecord->bet = 0;
                $betRecord->valid_bet = 0;
                $betRecord->win_lose = 0;
                $betRecord->payout_time = $request->input('datetime');
                $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $request->all());

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
                $betRecord->save();
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);
            }
            DB::commit();

            $balance = Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);

            return $this->response($balance);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    private function errorResponse(ErrorCodeEnum $code, string $msg = '', string $balance = '0'): JsonResponse
    {
        if (empty($msg)) {
            $msg = $code->name;
        }

        return response()->json([
            'code' => $code->value,
            'balance' => $balance,
            'msg' => $msg,
        ]);
    }

    private function response(string $balance = ''): JsonResponse
    {
        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'balance' => $balance,
        ]);
    }

    private function checkSign(Request $request): bool
    {
        $sign = $request->header('X-Sign', '');
        if (empty($sign)) {
            return false;
        }

        $content = json_encode(json_decode($request->getContent()));
        $checkString = $content . $this->infoDetail->APP_SECRET;
        $checkSign = md5($checkString);

        return $checkSign === $sign;
    }
}
