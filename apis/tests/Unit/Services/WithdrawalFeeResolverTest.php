<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\WithdrawalFeeTierTypeEnum;
use App\Enums\WithdrawalTaxSourceEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Services\MemberLimitResolver;
use App\Services\WithdrawalFee;
use App\Services\WithdrawalFeeResolver;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * WithdrawalFeeResolver 測試（會員提款手續費：會員群組平台手續費階梯 vs 銀行手續費）target/tickets#4353。
 *
 * - 會員無啟用中群組、或群組未設階梯 → 沿用銀行手續費（tax_type 0=固定 1=百分比，banks.tax 百分比存小數）。
 * - 群組有階梯 → 取 from <= 當日第幾筆 的最後一階；未達第一階 → 0。
 *   階梯百分比存百分比數值（3=3%、2.5=2.5%，非 0.03）。群組路徑手續費 >= 提現金額即擋 1958；銀行路徑不擋（維持現行）。
 *
 * php artisan test tests/Unit/Services/WithdrawalFeeResolverTest.php
 */
class WithdrawalFeeResolverTest extends TestCase
{
    /**
     * 以 mock 的 MemberLimitResolver 建 resolver；$group 為 resolveGroup 的回傳值。
     */
    private function resolver(?MemberGroup $group): WithdrawalFeeResolver
    {
        $limitResolver = Mockery::mock(MemberLimitResolver::class);
        $limitResolver->allows('resolveGroup')->andReturn($group);

        return new WithdrawalFeeResolver($limitResolver);
    }

    private function member(): Member
    {
        $member = new Member;
        $member->id = 1;

        return $member;
    }

    private function bank(int $taxType, mixed $tax): Bank
    {
        $bank = new Bank;
        $bank->tax_type = $taxType;
        $bank->tax = $tax;

        return $bank;
    }

    /**
     * @param  array<int, array{from: int, type: int, value: int|float|string}>|null  $tiers
     */
    private function group(?array $tiers): MemberGroup
    {
        $group = new MemberGroup;
        $group->withdraw_fee_tiers = $tiers;

        return $group;
    }

    /**
     * 標準兩階：第 4 筆起收 3%，第 11 筆起改收固定 50。
     */
    private function twoTierGroup(): MemberGroup
    {
        return $this->group([
            ['from' => 4, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 3],
            ['from' => 11, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 50],
        ]);
    }

    private function assertFee(string $expectedTax, WithdrawalTaxSourceEnum $expectedSource, WithdrawalFee $fee): void
    {
        $this->assertSame(0, Common::numCompare($fee->tax, $expectedTax, 5), "tax 預期 {$expectedTax}，實得 {$fee->tax}");
        $this->assertSame($expectedSource, $fee->source);
    }

    private function expect1958(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1958'));
    }

    // ==================== 銀行路徑 ====================

    #[TestDox('無群組＋銀行固定 20 → 手續費 20、來源銀行')]
    public function test_no_group_bank_fixed(): void
    {
        $fee = $this->resolver(null)->resolve($this->member(), $this->bank(0, '20.00000'), '1000', 1);

        $this->assertFee('20', WithdrawalTaxSourceEnum::BANK, $fee);
    }

    #[TestDox('無群組＋銀行百分比 0.02、提現 1000 → 手續費 20.00000')]
    public function test_no_group_bank_percent(): void
    {
        $fee = $this->resolver(null)->resolve($this->member(), $this->bank(1, '0.02000'), '1000', 1);

        $this->assertSame('20.00000', $fee->tax);
        $this->assertSame(WithdrawalTaxSourceEnum::BANK, $fee->source);
    }

    #[TestDox('有群組但未設階梯（null）→ 走銀行')]
    public function test_group_without_tiers_falls_back_to_bank(): void
    {
        $fee = $this->resolver($this->group(null))->resolve($this->member(), $this->bank(0, '15'), '1000', 5);

        $this->assertFee('15', WithdrawalTaxSourceEnum::BANK, $fee);
    }

    #[TestDox('有群組但階梯為空陣列 → 走銀行')]
    public function test_group_with_empty_tiers_falls_back_to_bank(): void
    {
        $fee = $this->resolver($this->group([]))->resolve($this->member(), $this->bank(0, '15'), '1000', 5);

        $this->assertFee('15', WithdrawalTaxSourceEnum::BANK, $fee);
    }

    #[TestDox('群組停用（resolveGroup 回 null）→ 走銀行')]
    public function test_disabled_group_falls_back_to_bank(): void
    {
        $fee = $this->resolver(null)->resolve($this->member(), $this->bank(1, '0.01'), '500', 20);

        $this->assertSame('5.00000', $fee->tax);
        $this->assertSame(WithdrawalTaxSourceEnum::BANK, $fee->source);
    }

    #[TestDox('銀行路徑不守門：銀行固定手續費大於提現金額也照算、不擋（維持現行）')]
    public function test_bank_path_not_guarded(): void
    {
        $fee = $this->resolver(null)->resolve($this->member(), $this->bank(0, '50'), '30', 1);

        $this->assertFee('50', WithdrawalTaxSourceEnum::BANK, $fee);
    }

    // ==================== 群組階梯 ====================

    #[TestDox('階梯 [4:3%, 11:50]：第 1、3 筆未達第一階 → 0、來源群組')]
    public function test_tiers_below_first_tier_is_zero(): void
    {
        foreach ([1, 3] as $seq) {
            $fee = $this->resolver($this->twoTierGroup())->resolve($this->member(), $this->bank(0, '20'), '1000', $seq);

            $this->assertFee('0', WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee);
        }
    }

    #[TestDox('階梯 [4:3%, 11:50]：第 4、10 筆、提現 1000 → 30.00000')]
    public function test_tiers_percent_tier(): void
    {
        foreach ([4, 10] as $seq) {
            $fee = $this->resolver($this->twoTierGroup())->resolve($this->member(), $this->bank(0, '20'), '1000', $seq);

            $this->assertSame('30.00000', $fee->tax, "seq {$seq}");
            $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee->source);
        }
    }

    #[TestDox('階梯 [4:3%, 11:50]：第 11、50 筆 → 固定 50.00000')]
    public function test_tiers_fixed_tier(): void
    {
        foreach ([11, 50] as $seq) {
            $fee = $this->resolver($this->twoTierGroup())->resolve($this->member(), $this->bank(0, '20'), '1000', $seq);

            $this->assertSame('50.00000', $fee->tax, "seq {$seq}");
            $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee->source);
        }
    }

    #[TestDox('階梯存的順序亂序仍依 from 升冪取階')]
    public function test_tiers_unsorted_storage(): void
    {
        $group = $this->group([
            ['from' => 11, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 50],
            ['from' => 4, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 3],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '1000', 5);

        $this->assertSame('30.00000', $fee->tax);
    }

    #[TestDox('階梯 from=1 → 第 1 筆就收')]
    public function test_tier_from_one_charges_first_withdrawal(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 10],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '1000', 1);

        $this->assertSame('10.00000', $fee->tax);
        $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee->source);
    }

    #[TestDox('精度：提現 333.33 × 3% → 9.99990')]
    public function test_percent_precision(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 3],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '333.33', 1);

        $this->assertSame('9.99990', $fee->tax);
    }

    #[TestDox('小數百分比：階梯 2.5%、提現 1000 → 25.00000')]
    public function test_decimal_percent_tier(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => '2.5'],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '1000', 1);

        $this->assertSame('25.00000', $fee->tax);
        $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee->source);
    }

    #[TestDox('DB 舊資料 value 為 JSON float 0.00001（(string) 會變 1.0E-5）→ 讀取時正規化、不拋 ValueError')]
    public function test_float_tier_value_is_normalized_on_read(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 0.00001],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '1000', 1);

        $this->assertSame('0.00001', $fee->tax);
    }

    #[TestDox('百分比 0 → 手續費 0.00000、來源群組（有階梯但免收，不回退銀行）')]
    public function test_zero_percent_tier_is_free_from_member_group(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 0],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '1000', 1);

        $this->assertSame('0.00000', $fee->tax);
        $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP, $fee->source);
    }

    #[TestDox('截斷：階梯 3%、提現 33.3333 → 0.99999（第 6 位起捨去，不四捨五入成 1.00000；釘住現行行為）')]
    public function test_percent_tier_truncates_beyond_scale(): void
    {
        $group = $this->group([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 3],
        ]);

        $fee = $this->resolver($group)->resolve($this->member(), $this->bank(0, '20'), '33.3333', 1);

        $this->assertSame('0.99999', $fee->tax);
    }

    // ==================== 群組路徑守門 1958 ====================

    #[TestDox('固定 50／提現 30 → 手續費大於提現金額擋 1958')]
    public function test_fixed_fee_greater_than_amount_throws(): void
    {
        $group = $this->group([['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 50]]);

        $this->expect1958();
        $this->resolver($group)->resolve($this->member(), $this->bank(0, '0'), '30', 1);
    }

    #[TestDox('固定 30／提現 30 → 手續費等於提現金額擋 1958')]
    public function test_fixed_fee_equal_to_amount_throws(): void
    {
        $group = $this->group([['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 30]]);

        $this->expect1958();
        $this->resolver($group)->resolve($this->member(), $this->bank(0, '0'), '30', 1);
    }

    #[TestDox('百分比 100 → 手續費等於提現金額擋 1958')]
    public function test_percent_hundred_throws(): void
    {
        $group = $this->group([['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 100]]);

        $this->expect1958();
        $this->resolver($group)->resolve($this->member(), $this->bank(0, '0'), '1000', 1);
    }
}
