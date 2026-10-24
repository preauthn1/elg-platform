<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Services\Payment\Providers\CCPayment\ReferenceId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CCPayment referenceId 的組成與反解：`{agent_cash_flow_order_type_id}-{member_id}`。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/ReferenceIdTest.php
 */
class ReferenceIdTest extends TestCase
{
    public function test_build_joins_channel_id_and_member_id_with_hyphen(): void
    {
        $this->assertSame('12-34', ReferenceId::build(12, 34));
    }

    public function test_parse_returns_channel_id_and_member_id(): void
    {
        $this->assertSame(['channel_id' => 12, 'member_id' => 34], ReferenceId::parse('12-34'));
    }

    #[DataProvider('invalidReferenceIds')]
    public function test_parse_rejects_values_not_in_our_format(string $value): void
    {
        $this->assertNull(ReferenceId::parse($value));
    }

    /** @return array<string, array{string}> */
    public static function invalidReferenceIds(): array
    {
        return [
            'empty' => [''],
            'plain digits' => ['1234'],
            'three parts' => ['12-34-56'],
            'letters' => ['12-abc'],
            'zero channel' => ['0-34'],
            'zero member' => ['12-0'],
            'leading space' => [' 12-34'],
            'negative' => ['-12-34'],
        ];
    }

    public function test_build_result_is_within_provider_length_limits(): void
    {
        $value = ReferenceId::build(PHP_INT_MAX, PHP_INT_MAX);

        $this->assertGreaterThanOrEqual(3, strlen($value));
        $this->assertLessThanOrEqual(64, strlen($value));
    }
}
