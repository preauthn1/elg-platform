<?php

declare(strict_types=1);

namespace App\Data\Report;

use App\Enums\Report\Sr02nPairEnum;
use App\Enums\Report\Sr02nSourceEnum;
use Illuminate\Support\Carbon;

/**
 * SR02N 列表／合計共用的篩選條件（target/tickets#4356）。
 *
 * 時間入參為前端時區牆鐘，建構時即轉成系統時區（UTC），查詢一律比對 UTC 原值。
 */
readonly class Sr02nFilter
{
    /**
     * @param  array<int, Sr02nPairEnum>  $pairs
     */
    public function __construct(
        public string $agentUsername,
        public string $memberUsername,
        public string $memberNickname,
        public string $transactionNo,
        public string $executionUsername,
        public Sr02nSourceEnum $source,
        public ?int $cashFlowId,
        public ?int $agentCashFlowId,
        public ?int $orderTypeId,
        public string $startUtc,
        public string $endUtc,
        public bool $isCreatedPeriod,
        public array $pairs,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Sr02nFilterRequest::validated()
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            agentUsername: (string) ($validated['agent_username'] ?? ''),
            memberUsername: (string) ($validated['member_username'] ?? ''),
            memberNickname: (string) ($validated['member_nickname'] ?? ''),
            transactionNo: (string) ($validated['transaction_no'] ?? ''),
            executionUsername: (string) ($validated['execution_username'] ?? ''),
            source: Sr02nSourceEnum::from((int) ($validated['source'] ?? Sr02nSourceEnum::ALL->value)),
            cashFlowId: self::nullableInt($validated['cash_flow_id'] ?? null),
            agentCashFlowId: self::nullableInt($validated['agent_cash_flow_id'] ?? null),
            orderTypeId: self::nullableInt($validated['order_type_id'] ?? null),
            startUtc: Carbon::parseFrontend((string) $validated['start_date'])->toSystemTime()->toDateTimeString(),
            endUtc: Carbon::parseFrontend((string) $validated['end_date'])->toSystemTime()->toDateTimeString(),
            isCreatedPeriod: (bool) ($validated['is_created_period'] ?? false),
            pairs: array_values(array_map(
                static fn (string $pair): Sr02nPairEnum => Sr02nPairEnum::from($pair),
                $validated['pairs'] ?? [],
            )),
        );
    }

    /** 會員帳號／暱稱／單號任一有值：代理兩段整段不併（代理間開洗分與會員、單號無關） */
    public function hasMemberFilter(): bool
    {
        return $this->memberUsername !== '' || $this->memberNickname !== '' || $this->transactionNo !== '';
    }

    public function hasPairs(): bool
    {
        return $this->pairs !== [];
    }

    /** @return array<int, Sr02nPairEnum> 作用於會員兩段的配對 */
    public function memberPairs(): array
    {
        return array_values(array_filter($this->pairs, static fn (Sr02nPairEnum $pair): bool => $pair->targetsMember()));
    }

    /** @return array<int, Sr02nPairEnum> 作用於代理兩段的配對 */
    public function agentPairs(): array
    {
        return array_values(array_filter($this->pairs, static fn (Sr02nPairEnum $pair): bool => $pair->targetsAgent()));
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
