<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Models\OrderType;
use Database\Seeders\CurrencySeeder;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsAmountFieldTest.php
 *
 * 父層支付通道的 info 預設帶一個 amount 表單欄位（會員輸入儲值金額）；資料檔的通道宣告
 * 'amount_field' => false 就不帶，給「會員自行轉帳、金額由回調決定」的通道用。
 * 這是逐通道宣告、不依通道 type 一律套用：CCPayment（永久地址模式）不要金額欄位，
 * PayEssence 同樣是加密貨幣卻仍要會員輸入金額（這項宣告只針對 CCPayment）。
 */
class SyncCashFlowsAmountFieldTest extends TestCase
{
    #[Group('rules/provider-ccpayment#R-5')]
    public function test_ccpayment_deposit_channels_are_synced_without_the_amount_form_field(): void
    {
        $this->seed(CurrencySeeder::class);

        $this->artisan('cash-flow:sync', ['--ids' => '129'])->assertExitCode(0);

        $infos = OrderType::query()->where('cash_flow_id', 129)->where('parent', 0)->pluck('info', 'key');

        $this->assertNotEmpty($infos);
        foreach ($infos as $key => $info) {
            $fields = collect(json_decode((string) $info, true)['data'] ?? [])->pluck('name')->all();
            $this->assertNotContains('amount', $fields, sprintf('%s 是永久地址通道，儲值頁不該有金額欄位', $key));
        }
    }

    public function test_parent_channel_info_keeps_the_amount_form_field_by_default(): void
    {
        $buildParentInfo = new ReflectionMethod(SyncCashFlowsCommand::class, 'buildParentInfo');
        $command = app(SyncCashFlowsCommand::class);

        $default = $buildParentInfo->invoke($command, ['name' => 'Bank Transfer']);
        $withoutAmount = $buildParentInfo->invoke($command, ['name' => 'Crypto', 'amount_field' => false]);

        $this->assertSame(['amount'], collect($default['data'])->pluck('name')->all());
        $this->assertSame([], $withoutAmount['data']);
    }
}
