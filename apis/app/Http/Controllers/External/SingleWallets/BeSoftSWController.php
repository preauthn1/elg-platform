<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\BeSoftSW\Enums\BeSoftActionEnum;
use App\Vendors\BeSoftSW\Enums\BeSoftStatusEnum;
use App\Vendors\Models\BeSoftSWAccount;
use App\Vendors\Models\BeSoftSWBetRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BeSoftSWController extends BaseController
{
    protected int $vendorId = VendorIdEnum::BESOFT->value;

    protected string $accountClass = BeSoftSWAccount::class;

    protected string $betRecordClass = BeSoftSWBetRecord::class;

    protected string $actionPrefix = 'Besoft_get_request_';

    protected string $vendorInfoOperatorField = 'extension1';

    /**
     * 預處理
     */
    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $action = $this->actionPrefix . basename(url()->current());
            $this->logRequest($request, $action);

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(BeSoftStatusEnum::SYSTEM_ERROR);
            }

            $params = $request->all();
            $uid = $params['uid'] ?? '';

            $this->member = $this->getMemberByThirdPartyUsername($uid);
            if (!$this->member) {
                return $this->errorResponse(BeSoftStatusEnum::USER_NOT_FOUND);
            }

            $this->vendorInfo = $this->getVendorInfo('', $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(BeSoftStatusEnum::SYSTEM_ERROR);
            }

            return parent::callAction($method, $parameters);
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(BeSoftStatusEnum::SYSTEM_ERROR);
        }
    }

    /**
     * 單一入口，依 action 欄位路由
     */
    public function entrance(Request $request): JsonResponse
    {
        $action = (int) $request->input('action');

        return match ($action) {
            BeSoftActionEnum::GET_BALANCE->value => $this->balance($request),
            BeSoftActionEnum::GAME_RESULT->value => $this->settle($request),
            default => $this->errorResponse(BeSoftStatusEnum::SYSTEM_ERROR),
        };
    }

    /**
     * 查詢餘額 (action=1)
     */
    public function balance(Request $request): JsonResponse
    {
        if ($this->account->status == 0) {
            return $this->errorResponse(BeSoftStatusEnum::USER_NOT_FOUND);
        }

        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return $this->successResponse(BeSoftActionEnum::GET_BALANCE, $balance);
    }

    /**
     * 遊戲結果入款/扣款 (action=3)
     */
    public function settle(Request $request): JsonResponse
    {
        $params = $request->all();

        return DB::transaction(function () use ($params) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $bet = Common::numDiv($params['bet'], $this->vendorInfo->rate, 4);
            $win = Common::numDiv($params['win'], $this->vendorInfo->rate, 4);

            if ($win == 0) {
                $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gid']);
                if (!$ableToBet) {
                    return $this->errorResponse(BeSoftStatusEnum::SYSTEM_ERROR);
                }
            }

            // 冪等性：相同 transId 重送時直接回傳成功
            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $params['transId'])->first();
            if ($oldBetRecord) {
                $balance = $walletDto->getTotalBalance();

                return $this->successResponse(BeSoftActionEnum::GAME_RESULT, $balance);
            }

            $betTime = Carbon::createFromTimestampMs($params['ts'])->utc()->format('Y-m-d H:i:s');

            $betRecord = new $this->betRecordClass([
                'bet_id' => $params['transId'],
                'username' => $params['uid'],
                'game_code' => $params['gid'],
                'bet' => $bet,
                'valid_bet' => $bet,
                'win' => $win,
                'win_lose' => Common::numSub($win, $bet, 4),
                'rolling_chip' => 0,
                'bet_time' => $betTime,
                'payout_time' => $betTime,
            ]);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();

            return $this->successResponse(BeSoftActionEnum::GAME_RESULT, $balance);
        });
    }

    protected function successResponse(BeSoftActionEnum $action, string $balance): JsonResponse
    {
        $balance = Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'action' => $action->value,
            'status' => BeSoftStatusEnum::SUCCESS->value,
            'balance' => (float) $balance,
            'currency' => $this->vendorInfo->currency ?? '',
        ]);
    }

    protected function errorResponse(BeSoftStatusEnum $status): JsonResponse
    {
        return response()->json(['status' => $status->value]);
    }
}
