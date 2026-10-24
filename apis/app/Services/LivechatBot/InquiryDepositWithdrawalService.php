<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\LivechatBotApiStatusEnum;
use App\Enums\LivechatBotTransactionTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Currency;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class InquiryDepositWithdrawalService
{
    /**
     * 單次查詢最多回傳的紀錄筆數（依 id 倒序，取最新 N 筆）
     */
    private const MAX_RECORDS = 50;

    public function __construct(
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * 查詢會員的儲值或提款紀錄
     *
     * @return array<int, array<string, mixed>>
     */
    public function inquiry(string $username, LivechatBotTransactionTypeEnum $transactionType): array
    {
        $member = $this->context->findMemberByUsername($username);

        if (!$member) {
            throw new ApiException('Member not found', 404);
        }

        return match ($transactionType) {
            LivechatBotTransactionTypeEnum::DEPOSIT => $this->buildDepositRecords($member->id),
            LivechatBotTransactionTypeEnum::WITHDRAW => $this->buildWithdrawalRecords($member->id),
        };
    }

    /**
     * 建立儲值紀錄回應
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDepositRecords(int $userId): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::with('currency')
            ->where('member_id', $userId)
            ->orderByDesc('id')
            ->limit(self::MAX_RECORDS)
            ->get();

        return $orders->map(fn (Order $order): array => $this->formatDepositRecord($order))->all();
    }

    /**
     * 建立提款紀錄回應
     *
     * 注意：Bank::currency() 關聯附帶 where('status', 1)，停用幣別時會取不到，
     * 因此改為先載入 Bank，再以 currency_id 建立獨立的 Currency map 查對。
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWithdrawalRecords(int $userId): array
    {
        /** @var Collection<int, WithdrawalOrder> $orders */
        $orders = WithdrawalOrder::with('bank')
            ->where('member_id', $userId)
            ->orderByDesc('id')
            ->limit(self::MAX_RECORDS)
            ->get();

        $currencyIds = $orders
            ->map(fn (WithdrawalOrder $order): ?int => $order->bank?->currency_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Currency> $currencyMap */
        $currencyMap = $currencyIds === []
            ? collect()
            : Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

        return $orders
            ->map(fn (WithdrawalOrder $order): array => $this->formatWithdrawalRecord($order, $currencyMap))
            ->all();
    }

    /**
     * 格式化單筆儲值紀錄
     *
     * @return array<string, mixed>
     */
    private function formatDepositRecord(Order $order): array
    {
        $id = $order->id;
        $status = $this->mapDepositStatus($order->status);
        $currency = (string) ($order->currency?->code ?? '');
        $amount = Common::numAdd('0', (string) $order->amount, 4);
        $userReceivedAmount = Common::numSub((string) $order->amount, (string) $order->tax, 4);
        $processingAt = $this->formatDateTime($order->pay_time);

        return [
            'id' => $id,
            'status' => $status->value,
            'currency' => $currency,
            'amount' => $amount,
            'user_received_amount' => $userReceivedAmount,
            'processing_at' => $processingAt,
            'remark' => null,
            'message' => $this->buildMessage(
                id: $id,
                status: $status->value,
                currency: $currency,
                amount: $amount,
                userReceivedAmount: $userReceivedAmount,
            ),
        ];
    }

    /**
     * 格式化單筆提款紀錄
     *
     * @param  Collection<int, Currency>  $currencyMap
     * @return array<string, mixed>
     */
    private function formatWithdrawalRecord(WithdrawalOrder $order, Collection $currencyMap): array
    {
        $id = $order->id;
        $status = $this->mapWithdrawalStatus($order->status);
        $currencyId = $order->bank?->currency_id;
        $currency = $currencyId !== null
            ? (string) ($currencyMap->get($currencyId)?->code ?? '')
            : '';
        $amount = Common::numAdd('0', (string) $order->amount, 4);
        $userReceivedAmount = Common::numSub((string) $order->amount, (string) $order->tax, 4);
        $processingAt = $this->formatDateTime($order->payment_time);

        return [
            'id' => $id,
            'status' => $status->value,
            'currency' => $currency,
            'amount' => $amount,
            'user_received_amount' => $userReceivedAmount,
            'processing_at' => $processingAt,
            'remark' => $order->memo !== '' ? $order->memo : null,
            'message' => $this->buildMessage(
                id: $id,
                status: $status->value,
                currency: $currency,
                amount: $amount,
                userReceivedAmount: $userReceivedAmount,
            ),
        ];
    }

    /**
     * 將內部 Deposit 狀態映射為對外 API 狀態
     */
    private function mapDepositStatus(int $status): LivechatBotApiStatusEnum
    {
        $case = OrderStatusEnum::tryFrom($status);

        if ($case === null) {
            Log::warning('LivechatBot inquiry unknown deposit status', ['status' => $status]);

            return LivechatBotApiStatusEnum::PENDING;
        }

        return LivechatBotApiStatusEnum::fromDepositStatus($case);
    }

    /**
     * 將內部 Withdrawal 狀態映射為對外 API 狀態
     */
    private function mapWithdrawalStatus(int $status): LivechatBotApiStatusEnum
    {
        $case = WithdrawalOrderStatusEnum::tryFrom($status);

        if ($case === null) {
            Log::warning('LivechatBot inquiry unknown withdrawal status', ['status' => $status]);

            return LivechatBotApiStatusEnum::PENDING;
        }

        return LivechatBotApiStatusEnum::fromWithdrawalStatus($case);
    }

    /**
     * 將 datetime 格式化為 Y-m-d H:i:s，null / 空字串 / legacy zero datetime 皆回傳 null
     */
    private function formatDateTime(mixed $datetime): ?string
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($datetime)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 建立人類可讀訊息陣列
     *
     * @return array<int, string>
     */
    private function buildMessage(
        int $id,
        string $status,
        string $currency,
        string $amount,
        string $userReceivedAmount,
    ): array {
        return [
            'ID: ' . $id,
            'Status: ' . $status,
            'Currency: ' . $currency,
            'Amount: ' . Common::numAdd('0', $amount, 2),
            'User Received Amount: ' . Common::numAdd('0', $userReceivedAmount, 2),
        ];
    }
}
