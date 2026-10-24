<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CashFlowEnum;
use App\Enums\CashFlowTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Bank\CreateOrderRequest;
use App\Http\Requests\Frontend\Bank\DeleteOrderRequest;
use App\Http\Requests\Frontend\Bank\UploadImageRequest;
use App\Http\Resources\Frontend\AutoDeposit\BankAccountsResource;
use App\Http\Resources\Frontend\AutoDeposit\OrderResource;
use App\Models\AgentCashFlow;
use App\Models\CustomerAuditTime;
use App\Models\Order;
use App\Services\AgentService;
use App\Services\FileStorageService;
use App\Services\MemberLimitResolver;
use App\Services\OrderService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Group('AutoDeposit (自助上分)')]
/**
 * @knowledge rules/deposit-order-fees
 */
class AutoDepositController extends Controller
{
    public function __construct(
        protected AgentService $agentService,
        protected OrderService $orderService,
        protected FileStorageService $fileStorageService,
        protected MemberLimitResolver $memberLimitResolver,
    ) {}

    /**
     * 取得銀行轉帳帳號
     *
     * OLD: /api/deposit/bank/getAccount
     */
    public function bankAccounts()
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;
        $bankAccountWhitelist = $this->memberLimitResolver->bankWhitelist($member);
        $group = $this->memberLimitResolver->resolveGroup($member);

        $order = $this->orderService->getLatestOrder($member, CashFlowTypeEnum::AUTO_DEPOSIT);

        $data = (object) [
            'type' => 1,
            'bank' => [],
        ];

        if (is_object($order)) {
            $bankId = $order->orderImages()->first()->bank_id;
            $accounts = $this->agentService->getAvailableBankAccounts(agent: $agent, bankId: $bankId);
            $data->type = 2;

            // Bank account is not available, delete it and give all available bank accounts.
            if (empty($accounts)) {
                $order->update(['status' => OrderStatusEnum::FAILED->value]);
                $accounts = $this->agentService->getAvailableBankAccounts(agent: $agent, bankAccountWhitelist: $bankAccountWhitelist);
                $data->type = 1;
            }

        } else {
            $accounts = $this->agentService->getAvailableBankAccounts(agent: $agent, bankAccountWhitelist: $bankAccountWhitelist);
            $data->type = 1;
        }

        // 每筆渠道 min/max 跟群組存款限額取嚴（渠道＝技術層，群組不得超過；群組 point 限額依各帳號匯率換算回 amount 再比）；無群組時原值零改變
        if ($group) {
            foreach ($accounts as $bankAccount) {
                $bankAccount->min_amount = (int) $this->memberLimitResolver->mergeFloor(
                    (string) $bankAccount->min_amount,
                    $this->memberLimitResolver->pointFloorToAmount($group->deposit_min, $bankAccount->rate),
                );
                $bankAccount->max_amount = (int) $this->memberLimitResolver->mergeCeiling(
                    (string) $bankAccount->max_amount,
                    $this->memberLimitResolver->pointCeilingToAmount($group->deposit_max, $bankAccount->rate),
                );
            }
        }

        $data->bank = $accounts;

        return ApiResponse::success(BankAccountsResource::make($data));
    }

    /**
     * 創建訂單
     *
     * OLD: /api/bank/order
     */
    public function createOrder(CreateOrderRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;

        if ($member->event_mode > 0) {
            return ApiResponse::error(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止儲值
        if ($member->is_event_v2) {
            return ApiResponse::error(__('error_message.event_mode.deposit_not_allowed'));
        }

        $order = Order::where('member_id', $member->id)
            ->where('status', OrderStatusEnum::CREATED->value)
            ->whereHas('agentCashFlow.cashFlow', fn ($q) => $q->where('type', CashFlowTypeEnum::AUTO_DEPOSIT->value))
            ->select('id', 'order_no', 'amount', 'status')
            ->orderBy('id', 'desc')
            ->first();

        // 查詢訂單
        if (!isset($request->bank_id) || !isset($request->amount)) {
            if (!is_object($order)) {
                return ApiResponse::error(__('error_message.1307')); // 查無訂單
            }

            $bankAccounts = $this->agentService->getAvailableBankAccounts(
                agent: $agent,
                bankId: $order->orderImages()->first()->bank_id
            );
            $data = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'bank_name' => $bankAccounts[0]->bank_name ?? '',
                'bank_code' => $bankAccounts[0]->bank_code ?? '',
                'account_name' => $bankAccounts[0]->account_name ?? '',
                'account_no' => $bankAccounts[0]->account_no ?? '',
                'amount' => $order->amount,
                'qrcode' => $bankAccounts[0]->qrcode ?? null,
                'status' => $order->status,
            ];

            return ApiResponse::success(OrderResource::make($data));
        }

        if (is_object($order)) {
            return ApiResponse::error(__('error_message.2043'));
        }

        // 自助開分禁止多開單 limit 0 為不限制
        $autoDepositProcessingLimit = $this->agentService->getSpecificAgentSetting(
            $agent, AgentCustomizedSettingKeyEnum::FINANCIAL_SETTINGS_AUTODEPOSIT_PROCESSING_LIMIT
        );
        if ($autoDepositProcessingLimit > 0) {
            $autoDepositProcessingCount = Order::where('member_id', $member->id)
                ->where(fn ($q) => $q
                    ->where('status', OrderStatusEnum::CREATED->value)
                    ->orWhere('status', OrderStatusEnum::SUCCESS->value)
                )
                ->whereHas('agentCashFlow.cashFlow', fn ($q) => $q->where('type', CashFlowTypeEnum::AUTO_DEPOSIT->value))
                ->count();
            if ($autoDepositProcessingCount >= $autoDepositProcessingLimit) {
                return ApiResponse::error(__('error_message.2043')); // 您仍有訂單正在處理中，請稍後再試
            }
        }

        // 建新單套用群組帳號白名單（無群組/未設定時為 null 不過濾），防止繞過顯示端直接對白名單外帳號建單
        $bankAccounts = $this->agentService->getAvailableBankAccounts(
            agent: $agent,
            bankId: $request->bank_id,
            bankAccountWhitelist: $this->memberLimitResolver->bankWhitelist($member),
        );

        if (empty($bankAccounts)) {
            return ApiResponse::error(__('error_message.2050')); // 銀行帳號已失效，請重新選擇
        }

        $bankAccount = $bankAccounts[0];
        if (Common::numCompare($request->amount, $bankAccount->min_amount) < 0 ||
            Common::numCompare($request->amount, $bankAccount->max_amount) > 0) {
            return ApiResponse::error(__('error_message.9997')); // 存款金額超出範圍，請重新輸入
        }

        $point = Common::numMul($request->amount, $bankAccount->rate);

        // 會員群組存款限額（單筆＋每日；無群組 / -1 時 no-op）
        $this->memberLimitResolver->checkDeposit($member, $point);
        $this->memberLimitResolver->checkDepositDailyAmount($member, $point);
        $this->memberLimitResolver->checkDepositDailyCount($member);

        try {
            DB::beginTransaction();
            $order = Order::create([
                // 'order_no' => substr(date('Ymd') . str_replace('-', '', Str::uuid7()->toString()), 0, 32), // 訂單編號 32 碼
                'order_no' => $this->orderService->getOrderNo(), // 訂單編號 32 碼
                'third_party_order_no' => '', // 第三方訂單編號
                'agent_cash_flow_id' => $bankAccount->agent_cash_flow_id,
                'order_type_id' => $bankAccount->order_type_id,
                'currency_id' => $bankAccount->currency_id,
                'member_id' => $member->id,
                'amount' => $request->amount,
                'rate' => $bankAccount->rate,
                'point' => $point,
                // 金流商手續費（成本）：自助上分沒有三方回調，審核在舊系統 API_Agent 完成、不經 APIs，
                // 所以建單時就依金流商戶的 cost 算好。
                // 彙總只計 status=3 的單，待審核／拒絕的單不會被算進成本 target/tickets#4361
                'payment_cost' => AgentCashFlow::query()->find((int) $bankAccount->agent_cash_flow_id)?->paymentCostFor($point) ?? '0',
                'balance' => 0,
                'status' => OrderStatusEnum::CREATED->value,
                'remark' => '', // 備註
                'admin' => 0,
            ]);

            $order->orderImages()->create([
                'bank_id' => $request->bank_id,
                'order_id' => $order->id,
                'image' => '',
                // 凍結建單當下的銀行戶口，戶口之後被改或停用，審核頁顯示的仍是會員當時轉入的帳戶
                // target/tickets#4545。account_no 含 BSB 前綴（`BSB__帳號`），顯示端照舊 explode。
                // 一律不存 JSON null：讀取端用 MySQL 的 `->>` 取 JSON null 會回字串 'null'，
                // 畫面會顯示成 null 字樣（整欄為 NULL 才是 SQL NULL，才會 fallback 回現行戶口）。
                'bank_snapshot' => [
                    'bank_name' => $bankAccount->bank_name,
                    'bank_code' => $bankAccount->bank_code,
                    'account_nickname' => $bankAccount->account_nickname ?? '',
                    'account_name' => $bankAccount->account_name,
                    'account_no' => $bankAccount->account_no,
                ],
            ]);

            DB::commit();

            return ApiResponse::success(OrderResource::make([
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'bank_name' => $bankAccount->bank_name ?? '',
                'bank_code' => $bankAccount->bank_code ?? '',
                'account_name' => $bankAccount->account_name ?? '',
                'account_no' => $bankAccount->account_no ?? '',
                'amount' => $order->amount,
                'qrcode' => $bankAccount->qrcode ?? null,
                'status' => $order->status,
            ]));
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error($e->getMessage());

            return ApiResponse::error(__('error_message.2004')); // 訂單建立失敗，請稍後再試
        }
    }

    /**
     * 刪除訂單
     *
     * OLD: /api/bank/delete
     */
    public function deleteOrder(DeleteOrderRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        try {
            DB::beginTransaction();

            Order::where('id', $request->order_id)
                ->where('member_id', $member->id)
                ->where('status', OrderStatusEnum::CREATED->value)
                ->update([
                    'status' => OrderStatusEnum::FAILED->value,
                ]);

            DB::commit();

            return ApiResponse::success();
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error($e->getMessage());

            return ApiResponse::error(__('error_message.2004')); // 訂單刪除失敗，請稍後再試
        }
    }

    /**
     * 訂單上傳圖片
     *
     * OLD: /api/bank/uploadImage
     */
    public function uploadImage(UploadImageRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        try {
            DB::beginTransaction();

            $order = Order::lockForUpdate()
                ->where('id', $request->order_id)
                ->where('member_id', $member->id)
                ->first();

            if (!is_object($order)) {
                return ApiResponse::error(__('error_message.auto_deposit.order_not_found'));
            } elseif ($order->status !== OrderStatusEnum::CREATED->value) {
                return ApiResponse::error(__('error_message.auto_deposit.order_status_not_allowed'));
            }

            $orderImage = $order->orderImages()->lockForUpdate()->first();

            if (!is_object($orderImage)) {
                return ApiResponse::error(__('error_message.auto_deposit.order_image_not_found'));
            } elseif (!empty($orderImage->image)) {
                return ApiResponse::error(__('error_message.auto_deposit.order_image_already_exists'));
            }

            $imagePath = $this->fileStorageService->storeBase64Image($request->image);

            $orderImage->update([
                'image' => $imagePath,
            ]);

            $order->update([
                'status' => OrderStatusEnum::SUCCESS->value,
            ]);

            CustomerAuditTime::create([
                'order_id' => $order->id,
                'cash_flow_id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
                'type' => 1,
                'operator_time' => now()->toDateTimeString(),
            ]);

            DB::commit();

            return ApiResponse::success([
                'order_no' => $order->order_no,
                'image' => $imagePath,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error($e->getMessage());

            return ApiResponse::error(__('error_message.auto_deposit.order_image_upload_failed'));
        }
    }
}
