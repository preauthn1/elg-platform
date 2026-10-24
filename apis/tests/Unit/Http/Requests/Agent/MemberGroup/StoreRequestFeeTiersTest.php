<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Agent\MemberGroup;

use App\Enums\WithdrawalFeeTierTypeEnum;
use App\Http\Requests\Agent\MemberGroup\StoreRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 會員群組 StoreRequest 的平台手續費階梯驗證（withdraw_fee_tiers）target/tickets#4353。
 *
 * 規則：≤10 階；from 整數 ≥1 且嚴格遞增；type 0=固定 1=百分比；value ≥0、最多 5 位小數、不接受科學記號，
 * 固定金額 ≤999999.99999（對齊 withdrawal_orders.tax decimal(11,5)），百分比 ≤100。
 * UpdateRequest 繼承 StoreRequest，規則一致。
 *
 * php artisan test tests/Unit/Http/Requests/Agent/MemberGroup/StoreRequestFeeTiersTest.php
 */
class StoreRequestFeeTiersTest extends TestCase
{
    /**
     * 套 StoreRequest 的 rules() ＋ withValidator()，回傳錯誤欄位清單。
     *
     * @param  array<int, array<string, mixed>>|null  $tiers
     * @return string[]
     */
    private function errorKeys(?array $tiers): array
    {
        return $this->runValidator($tiers)->errors()->keys();
    }

    /**
     * 套 StoreRequest 的 rules() ＋ withValidator()，回傳跑完的 validator。
     *
     * @param  array<int, array<string, mixed>>|null  $tiers
     */
    private function runValidator(?array $tiers): ValidatorInstance
    {
        $data = [
            'name' => 'VIP',
            'agent_id' => 1,
            'status' => 1,
            'default_setting' => 0,
            'withdraw_fee_tiers' => $tiers,
        ];

        $request = new StoreRequest;
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        return $validator;
    }

    private function tier(int|string $from, int|string $type, int|float|string $value): array
    {
        return ['from' => $from, 'type' => $type, 'value' => $value];
    }

    #[TestDox('from 嚴格遞增 → 通過')]
    public function test_strictly_increasing_passes(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(4, WithdrawalFeeTierTypeEnum::PERCENT->value, 3), $this->tier(11, WithdrawalFeeTierTypeEnum::PERCENT->value, 5)]));
    }

    #[TestDox('from 相等 → 擋')]
    public function test_equal_from_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.1.from', $this->errorKeys([$this->tier(4, WithdrawalFeeTierTypeEnum::PERCENT->value, 3), $this->tier(4, WithdrawalFeeTierTypeEnum::FIXED->value, 50)]));
    }

    #[TestDox('from 倒退 → 擋')]
    public function test_decreasing_from_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.1.from', $this->errorKeys([$this->tier(11, WithdrawalFeeTierTypeEnum::PERCENT->value, 3), $this->tier(4, WithdrawalFeeTierTypeEnum::FIXED->value, 50)]));
    }

    #[TestDox('from 0 → 擋')]
    public function test_from_zero_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.from', $this->errorKeys([$this->tier(0, WithdrawalFeeTierTypeEnum::PERCENT->value, 3)]));
    }

    #[TestDox('百分比 150 → 擋')]
    public function test_percent_over_hundred_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.value', $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::PERCENT->value, 150)]));
    }

    #[TestDox('百分比 100 → 規則面通過（實際建單時由 1958 擋）')]
    public function test_percent_hundred_passes_validation(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::PERCENT->value, 100)]));
    }

    #[TestDox('固定金額 -1 → 擋')]
    public function test_fixed_negative_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.value', $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, -1)]));
    }

    #[TestDox('固定金額超過 100 不受百分比上限影響 → 通過')]
    public function test_fixed_over_hundred_passes(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, 150)]));
    }

    #[TestDox('type 非 0/1 → 擋')]
    public function test_invalid_type_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.type', $this->errorKeys([$this->tier(1, 2, 3)]));
    }

    #[TestDox('11 階 → 擋')]
    public function test_eleven_tiers_fails(): void
    {
        $tiers = array_map(fn (int $i) => $this->tier($i, WithdrawalFeeTierTypeEnum::FIXED->value, 10), range(1, 11));

        $this->assertContains('withdraw_fee_tiers', $this->errorKeys($tiers));
    }

    #[TestDox('10 階 → 通過')]
    public function test_ten_tiers_passes(): void
    {
        $tiers = array_map(fn (int $i) => $this->tier($i, WithdrawalFeeTierTypeEnum::FIXED->value, 10), range(1, 10));

        $this->assertSame([], $this->errorKeys($tiers));
    }

    #[TestDox('空陣列 → 通過')]
    public function test_empty_array_passes(): void
    {
        $this->assertSame([], $this->errorKeys([]));
    }

    #[TestDox('null → 通過')]
    public function test_null_passes(): void
    {
        $this->assertSame([], $this->errorKeys(null));
    }

    #[TestDox('固定與百分比混合 → 通過')]
    public function test_mixed_types_pass(): void
    {
        $this->assertSame([], $this->errorKeys([
            $this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, 10),
            $this->tier(4, WithdrawalFeeTierTypeEnum::PERCENT->value, 3),
            $this->tier(11, WithdrawalFeeTierTypeEnum::FIXED->value, 50),
            $this->tier(20, WithdrawalFeeTierTypeEnum::PERCENT->value, '2.5'),
        ]));
    }

    #[TestDox('value 科學記號 1e3 → 擋（bcmath 讀不了，存進 DB 會讓建單每次 500）')]
    public function test_value_scientific_notation_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.value', $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, '1e3')]));
    }

    #[TestDox('value 6 位小數 0.000001 → 擋（超過 withdrawal_orders.tax 的 5 位）')]
    public function test_value_six_decimals_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.value', $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, '0.000001')]));
    }

    #[TestDox('固定金額 1000000 → 擋（超過 decimal(11,5) 上限 999999.99999）')]
    public function test_fixed_over_column_max_fails(): void
    {
        $this->assertContains('withdraw_fee_tiers.0.value', $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, 1000000)]));
    }

    #[TestDox('固定金額 999999.99999 → 通過（剛好等於上限）')]
    public function test_fixed_at_column_max_passes(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::FIXED->value, '999999.99999')]));
    }

    #[TestDox('百分比 2.5 → 通過（允許小數百分比）')]
    public function test_percent_decimal_passes(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(1, WithdrawalFeeTierTypeEnum::PERCENT->value, '2.5')]));
    }

    #[TestDox('from 非數字 abc → 只報一次錯，且不影響後續列的遞增檢查')]
    public function test_non_numeric_from_reports_once_and_keeps_increasing_check(): void
    {
        $validator = $this->runValidator([
            $this->tier('abc', WithdrawalFeeTierTypeEnum::FIXED->value, 10),
            $this->tier(4, WithdrawalFeeTierTypeEnum::FIXED->value, 10),
            $this->tier(11, WithdrawalFeeTierTypeEnum::FIXED->value, 10),
        ]);

        $this->assertCount(1, $validator->errors()->get('withdraw_fee_tiers.0.from'));
        $this->assertSame(['withdraw_fee_tiers.0.from'], $validator->errors()->keys());
    }

    #[TestDox('from 非數字 abc 之後的列倒退 → 遞增檢查照樣擋下倒退那列')]
    public function test_non_numeric_from_then_decreasing_still_fails(): void
    {
        $keys = $this->errorKeys([
            $this->tier('abc', WithdrawalFeeTierTypeEnum::FIXED->value, 10),
            $this->tier(4, WithdrawalFeeTierTypeEnum::FIXED->value, 10),
            $this->tier(3, WithdrawalFeeTierTypeEnum::FIXED->value, 10),
        ]);

        $this->assertSame(['withdraw_fee_tiers.0.from', 'withdraw_fee_tiers.2.from'], $keys);
    }

    #[TestDox('type 以字串 "1" 送入 → 通過（表單送字串也收）')]
    public function test_string_type_passes(): void
    {
        $this->assertSame([], $this->errorKeys([$this->tier(1, '1', 3)]));
    }
}
