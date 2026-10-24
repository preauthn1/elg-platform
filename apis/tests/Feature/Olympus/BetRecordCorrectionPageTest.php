<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Queries\BetRecordCorrection;
use App\Models\User;
use App\Models\VendorInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Olympus 後台「補帳查詢」頁。
 *
 * php artisan test tests/Feature/Olympus/BetRecordCorrectionPageTest.php
 */
class BetRecordCorrectionPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'parent' => 0,
            'username' => 'ops_tester_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
        ]), 'admin');
    }

    public function test_choosing_a_vendor_prefills_its_calc_time_zone(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::TITAN_EVO->value);
        $vendor->forceFill(['name' => 'TITAN EVO', 'status' => 1, 'calc_time_zone' => 0])->save();

        $component = Livewire::test(BetRecordCorrection::class)
            ->set('data.vendor_id', $vendor->id);

        // Select 的 Livewire 狀態是字串
        $this->assertSame('0', $component->get('data')['timezone']);
    }

    /**
     * 商戶留空 = 全部商戶；搜尋結果照 calc_time_zone 換算日界
     */
    public function test_search_fills_results_with_the_calc_time_zone_range(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::TITAN_EVO->value);
        $vendor->forceFill(['name' => 'TITAN EVO', 'status' => 1, 'calc_time_zone' => 8])->save();

        $component = Livewire::test(BetRecordCorrection::class)
            ->fillForm([
                'vendor_id' => $vendor->id,
                'timezone' => 8,
                'date' => '2026-06-08',
            ])
            ->call('search')
            ->assertHasNoFormErrors();

        $results = $component->get('results');

        $this->assertSame('2026-06-07 16:00:00', $results['start']);
        $this->assertSame('2026-06-08 16:00:00', $results['end']);
        $this->assertSame([], $results['corrections']);
        $this->assertSame('0.0000', $results['summary']['summary_time']['bet']);
        $this->assertSame('0.0000', $results['summary']['data_time']['bet']);
        $this->assertSame('0', $results['bet_record']['record_count']);
        $this->assertSame('全部商戶', $component->get('context')['vendor_info']);
    }

    /**
     * 指定商戶時 context 顯示該商戶；不屬於該遊戲商的商戶要擋下
     */
    public function test_search_with_specific_vendor_info_and_rejects_foreign_one(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::TITAN_EVO->value);
        $vendor->forceFill(['name' => 'TITAN EVO', 'status' => 1, 'calc_time_zone' => 8])->save();

        $vendorInfo = VendorInfo::query()->where('vendor_id', $vendor->id)->firstOrFail();

        $component = Livewire::test(BetRecordCorrection::class)
            ->fillForm([
                'vendor_id' => $vendor->id,
                'vendor_info_id' => $vendorInfo->id,
                'timezone' => 8,
                'date' => '2026-06-08',
            ])
            ->call('search');

        $this->assertStringContainsString(
            sprintf('(%d)', $vendorInfo->id),
            $component->get('context')['vendor_info'],
        );

        // 別的遊戲商的商戶 id：擋下且不產生結果
        $foreign = VendorInfo::factory()->create(['vendor_id' => VendorIdEnum::PP->value, 'status' => 1]);

        $component = Livewire::test(BetRecordCorrection::class)
            ->fillForm([
                'vendor_id' => $vendor->id,
                'vendor_info_id' => $foreign->id,
                'timezone' => 8,
                'date' => '2026-06-08',
            ])
            ->call('search');

        $this->assertNull($component->get('results'));
    }

    /** 由「重跑注單彙總」頁接續過來時，沿用網址帶的遊戲商／時區／日期 */
    public function test_mount_prefills_form_from_query_string(): void
    {
        $data = Livewire::withQueryParams([
            'vendor_id' => VendorIdEnum::TITAN_EVO->value,
            'timezone' => '0',
            'date' => '2026-08-20',
        ])->test(BetRecordCorrection::class)->get('data');

        $this->assertSame(VendorIdEnum::TITAN_EVO->value, (int) $data['vendor_id']);
        $this->assertSame('0', $data['timezone']);
        $this->assertStringStartsWith('2026-08-20', $data['date']);
    }
}
