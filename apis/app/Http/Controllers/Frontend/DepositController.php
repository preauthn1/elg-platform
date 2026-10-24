<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\CashFlowEnum;
use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Deposit\CreateOrderRequest;
use App\Http\Requests\Frontend\Deposit\CryptoAddressRequest;
use App\Http\Requests\Frontend\Deposit\TopUpChannelsRequest;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Resources\Frontend\Deposit\ChildOrderTypeResource;
use App\Http\Resources\Frontend\Deposit\OrderTypeGroupResource;
use App\Http\Resources\Frontend\Deposit\OrderTypeResource;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Order;
use App\Services\DepositService;
use App\Services\MemberCryptoWalletService;
use App\Services\MemberLimitResolver;
use App\Services\MemberService;
use App\Services\OrderService;
use App\Services\OrderTypeService;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Support\Facades\Log;

#[Group('Deposit (上分/充值)')]
/**
 * @knowledge rules/provider-ccpayment
 */
class DepositController extends Controller
{
    public function __construct(
        protected MemberService $memberService,
        protected MemberCryptoWalletService $memberCryptoWalletService,
        protected OrderService $orderService,
        protected OrderTypeService $orderTypeService,
        protected DepositService $depositService,
        protected PaymentService $paymentService,
        protected MemberLimitResolver $memberLimitResolver,
    ) {}

    /**
     * 獲取所有父級支付方式列表
     *
     * OLD: /api/deposit/getOrderType
     */
    public function topUpTypes()
    {
        $userData = $this->getUserContext();
        $languageId = $userData->languageId;
        $member = $userData->member;
        $agent = $userData->agent;

        // ! 1) 檢查支付密碼（早退）
        if ($this->depositService->shouldSetPaymentPassword($agent, $member)) {
            // 通知前端轉跳設定支付密碼
            return ApiResponse::error(
                message: __('error_message.9014'),
                code: HttpCode::HTTP_REDIRECT_SET_PAYMENT_PASSWORD->value,
                data: [
                    'member_id' => $member->id,
                    'mobile' => (($agent->mobile_validate == 0) ? '' : $member->mobile),
                ]
            );
        }

        // ! 2) 固定項（自動上分／自動洗分）
        $result = [];

        // 自動上分
        $autoDeposit = $this->orderTypeService->getAutoDeposit($agent, $languageId);
        if ($autoDeposit) {
            $result[] = $autoDeposit;
        }

        // 檢查代理配置，是否必須完成實名認證，才顯示自動洗分
        $requireRealName = $this->depositService->requireRealName($agent, $member);

        if ($requireRealName) {
            $autoWithdrawal = $this->orderTypeService->getAutoWithdrawal($agent, $languageId);
            if ($autoWithdrawal) {
                $result[] = $autoWithdrawal;
            }
        }

        // ! 3) 取可用支付渠道（AgentCashFlowOrderType 主表）
        $channels = $this->orderTypeService->getOrderTypes($agent, $languageId);
        $channels = $channels->when(!$requireRealName, function ($q) {
            // 實名認證篩選
            $q->where('orderType.need_first_name', 0);
        });
        $parentChannels = $channels->where('orderType.parent', 0);

        // ! 4) 會員加密貨幣收款地址 map（agent_cash_flow_order_type_id => wallet）：
        //      永久地址模式的通道每次都向目前商戶確認地址，失敗只留空地址、不擋清單
        $cryptoWalletMap = $this->memberCryptoWalletService->walletsForChannels($member, $parentChannels);

        foreach ($parentChannels as $agentCashFlowOrderType) {
            $orderType = $agentCashFlowOrderType->orderType;
            $agentCashFlow = $agentCashFlowOrderType->agentCashFlow;
            $cashFlow = $agentCashFlow->cashFlow;

            $cryptoWalletId = 0;
            $cryptoWalletAddress = '';
            if (in_array($cashFlow->id, CashFlowEnum::BIND_CRYPTO_WALLET)) {
                $cryptoWallet = $cryptoWalletMap->get($agentCashFlowOrderType->id);
                $cryptoWalletId = $cryptoWallet->id ?? 0;
                $cryptoWalletAddress = $cryptoWallet->address ?? '';
            }

            $result[] = (object) [
                'order_type_id' => $orderType->id,
                'currency_id' => $orderType->currency_id,
                'image_path' => $orderType->image_path,
                'order_type_info' => $orderType->info,
                'choose_bank' => $orderType->choose_bank,
                'type' => $orderType->type,
                'name' => $agentCashFlowOrderType->name,
                // 加密貨幣錢包
                'crypto_wallet_id' => $cryptoWalletId,
                'crypto_wallet_address' => $cryptoWalletAddress,
                // $cashFlow
                'cf_id' => $cashFlow->id,
                'cf_name' => $cashFlow->name ?? null,
                // $agentCashFlow
                'acf_id' => $agentCashFlow->id,
                'rate' => $agentCashFlow->rate,
                // $agentCashFlowOrderType
                'min_amount' => $agentCashFlowOrderType->min_amount,
                'max_amount' => $agentCashFlowOrderType->max_amount,
                'is_recommend' => $agentCashFlowOrderType->is_recommend,
                'settings' => $agentCashFlowOrderType->settings,
            ];
        }

        // 會員群組存款限額（顯示用）：point 依各渠道匯率換算回 amount 後與每筆 min/max 取嚴（渠道＝技術層，群組不得超過）；
        // 不動上面的查詢/組裝，無群組時整段跳過（原值原型別零改變）
        $group = $this->memberLimitResolver->resolveGroup($member);
        if ($group) {
            foreach ($result as $item) {
                // 自助洗分（提款）項不套存款限額
                if (!isset($item->min_amount, $item->max_amount)
                    || ($item->cf_id ?? null) == CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL) {
                    continue;
                }

                // 既有 min/max 慣例 0=不限制，先轉 null 再取嚴
                $item->min_amount = (int) ($this->memberLimitResolver->mergeFloor(
                    Common::numCompare((string) $item->min_amount, '0', 4) > 0 ? (string) $item->min_amount : null,
                    $this->memberLimitResolver->pointFloorToAmount($group->deposit_min, $item->rate ?? 1),
                ) ?? '0');
                $item->max_amount = (int) ($this->memberLimitResolver->mergeCeiling(
                    Common::numCompare((string) $item->max_amount, '0', 4) > 0 ? (string) $item->max_amount : null,
                    $this->memberLimitResolver->pointCeilingToAmount($group->deposit_max, $item->rate ?? 1),
                ) ?? '0');
            }
        }

        return ApiResponse::success(OrderTypeResource::collection($result));
    }

    /**
     * 獲取所有支付方式分組列表
     * vplus33 專用的
     */
    public function depositTypesGroup()
    {
        $userData = $this->getUserContext();
        $languageId = $userData->languageId;
        $member = $userData->member;
        $agent = $userData->agent;

        // ! 1) 檢查支付密碼（早退）
        if ($this->depositService->shouldSetPaymentPassword($agent, $member)) {
            // 通知前端轉跳設定支付密碼
            return ApiResponse::error(
                message: __('error_message.9014'),
                code: HttpCode::HTTP_REDIRECT_SET_PAYMENT_PASSWORD->value,
                data: [
                    'member_id' => $member->id,
                    'mobile' => (($agent->mobile_validate == 0) ? '' : $member->mobile),
                ]
            );
        }

        // 檢查代理配置，是否必須完成實名認證，才顯示自動洗分
        $requireRealName = $this->depositService->requireRealName($agent, $member);

        // ! 2) 取可用支付渠道（AgentCashFlowOrderType 主表）
        $channels = $this->orderTypeService->getOrderTypes($agent, $languageId);
        $channels = $channels->when(!$requireRealName, function ($q) {
            // 實名認證篩選
            $q->where('orderType.need_first_name', 0);
        });
        $parentChannels = $channels->where('orderType.parent', 0);
        $childChannels = $channels->where('orderType.parent', '>', 0)->groupBy('orderType.parent');

        // ! 3) 會員加密貨幣收款地址 map（agent_cash_flow_order_type_id => wallet）：
        //      永久地址模式的通道每次都向目前商戶確認地址，失敗只留空地址、不擋清單
        $cryptoWalletMap = $this->memberCryptoWalletService->walletsForChannels($member, $parentChannels);

        // ! 5) 組合需要的欄位
        $items = [];
        foreach ($parentChannels as $agentCashFlowOrderType) {
            $orderType = $agentCashFlowOrderType->orderType;
            $agentCashFlow = $agentCashFlowOrderType->agentCashFlow;

            $cryptoWallet = null;
            if (in_array($agentCashFlow->cash_flow_id, CashFlowEnum::BIND_CRYPTO_WALLET)) {
                $cryptoWallet = $cryptoWalletMap->get($agentCashFlowOrderType->id);
            }

            $data = [
                'order_type_id' => $orderType->id,
                'currency_id' => $orderType->currency_id,
                'image_path' => $orderType->image_path,
                'order_type_info' => $orderType->info,
                'choose_bank' => $orderType->choose_bank,
                'banks' => $orderType->choose_bank ? ($childChannels->get($orderType->id) ?? []) : [],
                'type' => $orderType->type,
                'name' => $agentCashFlowOrderType->name,
                // 加密貨幣錢包
                'crypto_wallet_id' => $cryptoWallet->id ?? 0,
                'crypto_wallet_address' => $cryptoWallet->address ?? '',
                // $cashFlow
                'cf_id' => $agentCashFlow->cash_flow_id,
                // 'cf_name' => $agentCashFlow->cashFlow->name ?? null,
                // $agentCashFlow
                // 'acf_id' => $agentCashFlow->id,
                'rate' => $agentCashFlow->rate,
                // $agentCashFlowOrderType
                'min_amount' => $agentCashFlowOrderType->min_amount,
                'max_amount' => $agentCashFlowOrderType->max_amount,
                'settings' => $agentCashFlowOrderType->settings,
            ];

            $items[] = (object) $data;
        }

        // ! 6) 加入自助上分／自助洗分
        $autoDeposit = $this->orderTypeService->getAutoDeposit($agent, $languageId);
        if ($autoDeposit) {
            $autoDeposit->type = 14; // 自助上分
            $autoDeposit->banks = [];
            $items[] = $autoDeposit;
        }

        if ($requireRealName) {
            $autoWithdrawal = $this->orderTypeService->getAutoWithdrawal($agent, $languageId);
            if ($autoWithdrawal) {
                $autoWithdrawal->type = 15; // 自助洗分
                $autoWithdrawal->banks = [];
                $items[] = $autoWithdrawal;
            }
        }

        // 會員群組存款限額（顯示用）：point 依各渠道匯率換算回 amount 後與每筆 min/max 取嚴（渠道＝技術層，群組不得超過）；
        // 不動上面的查詢/組裝，無群組時整段跳過（原值原型別零改變）
        $group = $this->memberLimitResolver->resolveGroup($member);
        if ($group) {
            foreach ($items as $item) {
                // choose_bank 型渠道的 banks 子項也要套用（子項 model 來自共用快取，clone 後才改）
                if (!empty($item->banks)) {
                    $item->banks = collect($item->banks)->map(
                        fn ($childChannel) => $this->memberLimitResolver->cloneChannelWithDepositLimits($childChannel, $group)
                    );
                }

                // 自助洗分（提款）項不套存款限額
                if (!isset($item->min_amount, $item->max_amount)
                    || ($item->cf_id ?? null) == CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL) {
                    continue;
                }

                // 既有 min/max 慣例 0=不限制，先轉 null 再取嚴
                $item->min_amount = (int) ($this->memberLimitResolver->mergeFloor(
                    Common::numCompare((string) $item->min_amount, '0', 4) > 0 ? (string) $item->min_amount : null,
                    $this->memberLimitResolver->pointFloorToAmount($group->deposit_min, $item->rate ?? 1),
                ) ?? '0');
                $item->max_amount = (int) ($this->memberLimitResolver->mergeCeiling(
                    Common::numCompare((string) $item->max_amount, '0', 4) > 0 ? (string) $item->max_amount : null,
                    $this->memberLimitResolver->pointCeilingToAmount($group->deposit_max, $item->rate ?? 1),
                ) ?? '0');
            }
        }

        // ! 7) 依 type 分組，群組內按 sorts 降冪，輸出前移除 sorts
        $grouped = collect($items)
            ->sortBy('type')
            ->groupBy('type')
            ->map(function ($rows, $type) {
                return [
                    'type' => __('order_type.' . $type),
                    'items' => $rows,
                ];
            })
            ->values();

        $resource = OrderTypeGroupResource::collection($grouped);

        return ApiResponse::success($resource);
    }

    /**
     * 取得子級支付方式列表
     *
     * OLD: /api/deposit/getChildOrderType
     */
    public function topUpChannels(TopUpChannelsRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;
        $languageId = $userData->languageId;
        $channels = $this->orderTypeService->getOrderTypes($agent, $languageId);
        $data = $channels->where('orderType.parent', $request->order_type_id);

        // 會員群組存款限額（顯示用）：getOrderTypes 集合來自共用快取，clone 後才改，無群組時原樣輸出
        $group = $this->memberLimitResolver->resolveGroup($member);
        if ($group) {
            $data = $data->map(
                fn ($channel) => $this->memberLimitResolver->cloneChannelWithDepositLimits($channel, $group)
            );
        }

        return ApiResponse::success(ChildOrderTypeResource::collection($data));
    }

    /**
     * 充值訂單
     */
    public function orders(PaginationRequest $request)
    {
        // context
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        // request
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 10;

        $orders = Order::query()
            ->with([
                'orderType' => function ($query) {
                    $query->select(['id', 'cash_flow_id', 'image_path', 'name']);
                },
                'orderTypeName' => function ($query) use ($languageId) {
                    $query->select(['id', 'language_id', 'type_id', 'name']);
                    $query->where('language_id', $languageId);
                },
            ])
            ->select(['id', 'order_type_id', 'cash_flow_id', 'extra_content', 'currency_id', 'member_id', 'amount', 'point', 'balance', 'status', 'created_at', 'updated_at'])
            ->where('member_id', $member->id)
            ->has('orderType')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // 格式化資料
        $orders = $orders->map(function ($order) {
            $orderTypeName = $order->orderTypeName?->name ?? $order->orderType->name;

            $statusName = match ($order->status) {
                1 => __('param.successful_application'),
                2 => __('param.fail'),
                3 => __('param.succeed_top_up'),
                default => __('param.create'),
            };
            // VG Pay 需要上傳轉帳收據
            $depositUrl = '';
            if ($order->cash_flow_id == CashFlowEnum::DEPOSIT_VG_PAY) {
                $extraContent = json_decode($order->extra_content, true);
                $depositUrl = $extraContent['redirect_url'] ?? '';
            }

            return [
                'order_id' => $order->id,
                'cash_flow_id' => $order->orderType->cash_flow_id,
                'deposit_url' => $depositUrl,
                'extra_content' => $order->extra_content,
                'order_type' => $orderTypeName,
                'image_path' => $order->orderType->image_path,
                'currency_id' => $order->currency_id,
                'member_id' => $order->member_id,
                'amount' => Common::numFloor($order->amount, 2),
                'point' => Common::numFloor($order->point, 2),
                'balance' => Common::numFloor($order->balance, 2),
                'status' => $order->status,
                'status_name' => $statusName,
                'created_at' => $order->created_at->toFrontendTime()->format('Y-m-d H:i:s'),
                'updated_at' => $order->updated_at->toFrontendTime()->format('Y-m-d H:i:s'),
            ];
        })->toArray();

        return ApiResponse::success($orders);
    }

    /**
     * 固定收款地址（永久地址模式）
     *
     * 會員點選綁定加密貨幣錢包（CashFlowEnum::BIND_CRYPTO_WALLET）的支付通道時可呼叫：
     * 向目前商戶確認地址並對齊 member_crypto_wallets（地址變了就換發），不走快取。
     * 回傳欄位與存款方式清單的 crypto_wallet_id／crypto_wallet_address 同名同義。
     * 存款方式清單會替永久地址模式的通道自動取址（MemberCryptoWalletService::walletsForChannels），
     * 本端點給 App 主動取址或清單取址失敗後的重取，不是唯一的取址入口；這裡取址失敗會直接回錯誤。
     */
    public function cryptoAddress(CryptoAddressRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;

        $orderTypeId = (int) $request->validated()['order_type_id'];

        $orderType = $this->depositService->getOrderType($agent, $orderTypeId);
        $agentCashFlow = $orderType?->agentCashFlow;
        $channel = $agentCashFlow?->agentCashFlowOrderTypes->firstWhere('order_type_id', $orderTypeId);

        if (!$orderType || !$orderType->cashFlow || !$agentCashFlow || !$channel) {
            Log::warning(sprintf('會員 %s 對未配置的支付通道 %s 取收款地址', $member->id, $orderTypeId));
            throw new ApiException('Payment channel not found');
        }

        if (!in_array((int) $agentCashFlow->cash_flow_id, CashFlowEnum::BIND_CRYPTO_WALLET, true)) {
            throw new ApiException('Payment channel does not provide a deposit address');
        }

        $wallet = $this->memberCryptoWalletService->getOrCreateForChannel($member, $channel);

        return ApiResponse::success([
            'crypto_wallet_id' => $wallet->id,
            'crypto_wallet_address' => $wallet->address,
        ]);
    }

    /**
     * 提交充值訂單
     *
     * type: 0=失敗, 1=轉址, 2=QRCode, 3=form post
     */
    public function createOrder(CreateOrderRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;

        $input = $request->validated();

        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            return ApiResponse::error(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止儲值
        if ($member->is_event_v2) {
            return ApiResponse::error(__('error_message.event_mode.deposit_not_allowed'));
        }

        // ! 一次拿到 OrderType, cashFlow, agentCashFlow, agentCashFlowOrderTypes
        $orderType = $this->depositService->getOrderType($agent, $input['order_type_id']);

        if (!$orderType) {
            Log::warning(sprintf('會員 %s 嘗試使用未配置的支付渠道 %s 充值', $member->id, $input['order_type_id']));
            throw new ApiException('Payment channel not found');
        }

        /** @var CashFlow $cashFlow 渠道關聯的支付方式 */
        $cashFlow = $orderType->cashFlow;

        if (!$cashFlow) {
            // 支付方式存在但代理未配置或啟用
            Log::warning(sprintf('會員 %s 嘗試使用未配置的支付方式 %s 充值', $member->id, $input['order_type_id']));
            throw new ApiException('Payment method not found');
        }

        /** @var AgentCashFlow $agentCashFlow 代理的支付方式配置 */
        $agentCashFlow = $orderType->agentCashFlow;

        if (!$agentCashFlow) {
            // 支付方式存在但代理未配置或啟用
            Log::warning(sprintf('會員 %s 嘗試使用未配置的代理支付方式 %s 充值', $member->id, $input['order_type_id']));
            throw new ApiException('Payment method not available');
        }

        // 綁定加密貨幣錢包的支付通道不在這裡建存款單：會員向固定地址轉帳，存款單在收到金流商回調
        // 並回查後才建立（永久地址模式）。地址由 deposit/crypto-address 取得。擋在建單之前，不留空單。
        if (in_array((int) $cashFlow->id, CashFlowEnum::BIND_CRYPTO_WALLET, true)) {
            Log::warning(sprintf('會員 %s 對綁定加密貨幣錢包的支付通道 %s 送出建單請求', $member->id, $input['order_type_id']));
            throw new ApiException('Payment channel does not accept deposit orders');
        }

        $agentCashFlowOrderTypes = $agentCashFlow->agentCashFlowOrderTypes;

        if ($agentCashFlowOrderTypes->isEmpty()) {
            // 每個支付方式至少會有一筆渠道
            Log::warning(sprintf('會員 %s 嘗試使用無可用渠道的支付方式 %s 充值', $member->id, $input['order_type_id']));
            throw new ApiException('No available payment channel');
        }

        // lad('$orderType', $orderType);
        // lad('$cashFlow', $orderType->cashFlow);
        // lad('$agentCashFlow', $orderType->agentCashFlow);
        // lad('$agentCashFlowOrderTypes', $agentCashFlowOrderTypes);

        // 獲取所有額外欄位（自動包含所有動態欄位）
        $extraContent = $request->getExtraFields();

        $amount = (string) ($input['amount'] ?? '0');

        // 會員群組存款限額（單筆＋每日；無群組 / -1 時 no-op）；
        // 部分渠道建單不帶金額、實際金額由回調決定，無金額時不套單筆/每日金額限額；
        // 每日「次數」與金額無關，無論該次建單有無金額都要檢查，防止靠無金額渠道連續建單繞過每日次數限制。
        $this->memberLimitResolver->checkDepositDailyCount($member);

        if (Common::numCompare($amount, '0', 4) > 0) {
            $groupPoint = Common::numMul($amount, (string) ($agentCashFlow->rate ?? 1), 4);
            $this->memberLimitResolver->checkDeposit($member, $groupPoint);
            $this->memberLimitResolver->checkDepositDailyAmount($member, $groupPoint);
        }

        try {
            $order = $this->orderService->createOrder($orderType, $agentCashFlow, $member, $amount, $extraContent);
        } catch (Exception $e) {
            Log::error('Create deposit order failed', [
                'member_id' => $member->id,
                'order_type_id' => $orderType->id,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }

        if (!$order) {
            // 可能因為生成訂單號重試次數到達上限而失敗
            throw new ApiException(__('error_message.1316'));
        }

        Log::info('Create deposit order', [
            'member_id' => $member->id,
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $cashFlow->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'amount' => $order->amount,
        ]);

        // 調用三方
        try {
            $provider = $this->paymentService->getProvider($agentCashFlow);

            $params = DepositRequestData::from([
                'member' => $member,
                'order' => $order,
                'callbackUrl' => Common::depositCallbackUrl($agentCashFlow->id),
                'returnUrl' => Common::depositReturnUrl($input['domain']),
                'ip' => Common::getIp(),
            ]);

            /** @var DepositResponseData $result */
            $result = $provider->deposit($params);
        } catch (Exception $e) {
            Log::error('Deposit to payment provider failed', [
                'member_id' => $member->id,
                'order_id' => $order->id,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }

        /**
         * ! 前端規則
         * type:0 支付系統忙碌中
         * type:1 跳轉支付頁
         * type:3 顯示回傳的html
         * type:4 顯示成功,回錢包頁
         * type:11 顯示qrcode
         * type:9 TngPin 返回自定義的code, message
         */

        // {
        //     'type': 1,
        //     'url': 'https://***********',
        //     'html': null,
        //     'order_no': '********',
        //     'third_party_order_no': '*********'
        // }

        if ($result->type === 9) {
            return ApiResponse::customCode(
                code: $result->code,
                message: $result->message
            );
        }

        return ApiResponse::success($result->toArray());
    }
}
