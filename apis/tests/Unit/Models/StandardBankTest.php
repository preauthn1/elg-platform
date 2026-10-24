<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Bank;
use App\Models\Currency;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StandardBankTest extends TestCase
{
    use DatabaseTransactions;

    public function test_aliases_is_cast_to_array(): void
    {
        $bank = StandardBank::query()->create([
            'code' => 'MYR_MAYBANK', 'currency_id' => 1, 'currency_code' => 'MYR',
            'type' => 1, 'name' => 'Maybank', 'swift' => 'MBBEMYKL',
            'aliases' => ['Maybank', 'MBB'], 'status' => 1,
        ]);

        $this->assertIsArray($bank->fresh()->aliases);
        $this->assertContains('MBB', $bank->fresh()->aliases);
    }

    public function test_does_not_use_soft_deletes(): void
    {
        $this->assertArrayNotHasKey('deleted_at', (new StandardBank)->getCasts());
        $this->assertFalse(method_exists(StandardBank::class, 'bootSoftDeletes'));
    }

    public function test_banks_relation_uses_standard_bank_id(): void
    {
        $standardBank = StandardBank::query()->create([
            'code' => 'TESTBANK', 'currency_id' => 1, 'currency_code' => 'MYR',
            'type' => 1, 'name' => 'Test Bank', 'status' => 1,
        ]);

        $bank = Bank::create([
            'agent_group_id' => 0,
            'currency_id' => 1,
            'type' => 1,
            'code' => 'TESTBANK',
            'name' => 'Test Bank',
            'status' => 1,
            'standard_bank_id' => $standardBank->id,
        ]);

        $related = $standardBank->banks;

        $this->assertCount(1, $related);
        $this->assertTrue($related->first()->is($bank));
    }

    public function test_currency_relation(): void
    {
        $currency = Currency::create([
            'code' => 'TST',
            'name' => 'Test Currency',
            'rate' => '1.0000',
            'admin' => 0,
        ]);

        $standardBank = StandardBank::query()->create([
            'code' => 'CURRTEST',
            'currency_id' => $currency->id,
            'currency_code' => 'TST',
            'type' => 1,
            'name' => 'Currency Test Bank',
            'status' => 1,
        ]);

        $related = $standardBank->currency;

        $this->assertInstanceOf(Currency::class, $related);
        $this->assertSame($currency->id, $related->id);
    }
}
