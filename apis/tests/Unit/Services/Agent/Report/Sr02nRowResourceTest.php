<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent\Report;

use App\Http\Resources\Agent\Report\Sr02nRowResource;
use App\Services\Agent\Report\Sr02n\Segments\AbstractSr02nSegment;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * SR02N 列表單列映射（target/tickets#4356）：型別、來源、狀態、時間逐條對照 SR02 的 SQL CASE。
 *
 * php artisan test tests/Unit/Services/Agent/Report/Sr02nRowResourceTest.php
 */
class Sr02nRowResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('custom.frontend.timezone', 'Asia/Taipei');
    }

    /** @return array<string, mixed> */
    private function map(array $attributes): array
    {
        $row = (object) array_merge(
            array_fill_keys(AbstractSr02nSegment::COLUMNS, null),
            ['id' => 1, 'created_at' => '2026-09-09 16:30:00', 'point' => '100', 'balance' => '900.5', 'tax' => null, 'actual_point' => null],
            $attributes,
        );

        return (new Sr02nRowResource($row))->toArray(Request::create('/'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int|string|null, 2: string, 3: string|null}>
     */
    public static function rows(): array
    {
        return [
            'main 3 三方充值' => [['segment' => 'main', 'raw_type' => 3, 'deposit_cash_flow_name' => 'ALLPay'], 'third_party_deposit', 'cash_flow', 'ALLPay'],
            'main 5 無提款單' => [['segment' => 'main', 'raw_type' => 5], 5, 'customer_service', null],
            'main 5 客服提現' => [['segment' => 'main', 'raw_type' => 5, 'withdrawal_order_id' => 9], 'cs_payout', 'customer_service', null],
            'main 5 三方代付' => [['segment' => 'main', 'raw_type' => 5, 'withdrawal_order_id' => 9, 'payout_cash_flow_id' => 23, 'payout_cash_flow_name' => 'AllPay'], 'third_party_payout', 'cash_flow', 'AllPay'],
            'main 19 系統' => [['segment' => 'main', 'raw_type' => 19], 19, 'system', null],
            'main 24 系統' => [['segment' => 'main', 'raw_type' => 24], 24, 'system', null],
            'main 22 預付網' => [['segment' => 'main', 'raw_type' => 22], 22, 'ota', null],
            'main 4 客服' => [['segment' => 'main', 'raw_type' => 4], 4, 'customer_service', null],
            'game 5 三方充值' => [['segment' => 'game', 'raw_type' => 5, 'deposit_cash_flow_name' => 'NCPay'], 'third_party_deposit', 'cash_flow', 'NCPay'],
            'game 6→4' => [['segment' => 'game', 'raw_type' => 6], 4, 'customer_service', null],
            'game 8→14' => [['segment' => 'game', 'raw_type' => 8], 14, 'customer_service', null],
            'game 9→36' => [['segment' => 'game', 'raw_type' => 9], 36, 'customer_service', null],
            'agent_credit 1' => [['segment' => 'agent_credit', 'raw_type' => 1, 'raw_status' => 1], 'transfer_to_agent', 'customer_service', null],
            'agent_credit 2' => [['segment' => 'agent_credit', 'raw_type' => 2, 'raw_status' => 1], 'deduct_from_agent', 'customer_service', null],
            'agent_credit 其他（SR02 CASE 無 ELSE）' => [['segment' => 'agent_credit', 'raw_type' => 8, 'raw_status' => 1], null, 'customer_service', null],
            'agent_wallet 1→4' => [['segment' => 'agent_wallet', 'raw_type' => 1, 'raw_status' => 1], 4, 'customer_service', null],
            'agent_wallet 2→36' => [['segment' => 'agent_wallet', 'raw_type' => 2, 'raw_status' => 1], 36, 'customer_service', null],
            'agent_wallet 3' => [['segment' => 'agent_wallet', 'raw_type' => 3, 'raw_status' => 1], 3, 'customer_service', null],
        ];
    }

    #[DataProvider('rows')]
    #[TestDox('型別與來源映射')]
    public function test_type_and_source(array $attributes, int|string|null $type, string $sourceKind, ?string $sourceName): void
    {
        $mapped = $this->map($attributes);

        $this->assertSame($type, $mapped['type']);
        $this->assertSame($sourceKind, $mapped['source_kind']);
        $this->assertSame($sourceName, $mapped['source_name']);
    }

    #[TestDox('狀態：會員列帳戶暱稱或「-」；代理列 success／fail')]
    public function test_status(): void
    {
        $this->assertSame('ACC-1', $this->map(['segment' => 'main', 'raw_type' => 4, 'account_nickname' => 'ACC-1'])['status']);
        $this->assertSame('-', $this->map(['segment' => 'game', 'raw_type' => 6])['status']);
        $this->assertSame('success', $this->map(['segment' => 'agent_credit', 'raw_type' => 1, 'raw_status' => 1])['status']);
        $this->assertSame('fail', $this->map(['segment' => 'agent_wallet', 'raw_type' => 1, 'raw_status' => 0])['status']);
    }

    #[TestDox('時間轉前端時區、金額 4 位、platform／wallet_type 由段別給、手續費原樣透傳')]
    public function test_common_fields(): void
    {
        $mapped = $this->map(['segment' => 'agent_wallet', 'raw_type' => 1, 'raw_status' => 1, 'tax' => '3.0000', 'actual_point' => '97.0000']);

        $this->assertSame('2026-09-10 00:30:00', $mapped['created_at']);
        $this->assertSame(['100.0000', '900.5000'], [$mapped['point'], $mapped['balance']]);
        $this->assertSame([2, 2], [$mapped['platform'], $mapped['wallet_type']]);
        $this->assertSame(['3.0000', '97.0000'], [$mapped['tax'], $mapped['actual_point']]);
        $this->assertSame([1, 1], [$this->map(['segment' => 'main', 'raw_type' => 4])['platform'], $this->map(['segment' => 'main', 'raw_type' => 4])['wallet_type']]);
    }
}
