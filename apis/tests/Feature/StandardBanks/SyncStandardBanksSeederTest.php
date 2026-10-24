<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Enums\StandardBankTypeEnum;
use App\Models\Currency;
use App\Models\StandardBank;
use Database\Seeders\SyncStandardBanksSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SyncStandardBanksSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // xsgcasino_testing.currencies 為空表；用 DB::table 繞過 model $guarded，
        // 並提供 rate/admin 等必填欄位（Currency model 不提供這些欄位的預設）。
        foreach (['MYR', 'VND', 'SGD', 'IDR', 'INR'] as $code) {
            if (!DB::table('currencies')->where('code', $code)->exists()) {
                DB::table('currencies')->insert(['code' => $code, 'name' => $code, 'rate' => 1, 'admin' => 0]);
            }
        }
    }

    /** 以 fixture 覆寫 config 條目的匿名子類。 */
    private function seederWithEntries(array $entries): SyncStandardBanksSeeder
    {
        return new class($entries) extends SyncStandardBanksSeeder
        {
            public function __construct(private readonly array $entries) {}

            protected function loadEntries(): array
            {
                return $this->entries;
            }
        };
    }

    #[Group('rules/standard-bank-list#R-1')]
    #[Group('rules/standard-bank-list#R-2')]
    public function test_run_upserts_real_config_and_is_idempotent(): void
    {
        $seeder = new SyncStandardBanksSeeder;

        $seeder->run();
        $myrId = Currency::query()->where('code', 'MYR')->value('id');
        $maybank = StandardBank::query()->where('currency_id', $myrId)->where('code', 'MYR_MAYBANK')->first();
        $this->assertNotNull($maybank, 'code 應原樣保留幣別前綴存為 MYR_MAYBANK');
        $this->assertSame('MYR', $maybank->currency_code);

        $secondChanged = $seeder->run();
        $this->assertSame(0, $secondChanged, '內容未變的重跑應回報 0 變更');
    }

    #[Group('rules/standard-bank-list#R-1')]
    public function test_updates_existing_row_when_config_changes(): void
    {
        $myrId = (int) Currency::query()->where('code', 'MYR')->value('id');
        $seeder = $this->seederWithEntries([
            ['code' => 'MYR_MAYBANK', 'name' => 'Maybank Renamed', 'currency' => 'MYR', 'aliases' => ['Maybank']],
        ]);

        (new SyncStandardBanksSeeder)->run(); // 先以真實 config 建出基準列

        $changed = $seeder->run();

        $this->assertSame(1, $changed);
        $this->assertDatabaseHas('standard_banks', [
            'code' => 'MYR_MAYBANK',
            'currency_id' => $myrId,
            'name' => 'Maybank Renamed',
        ]);
    }

    #[Group('rules/standard-bank-list#R-4')]
    public function test_writes_swift_when_provided_and_preserves_when_absent(): void
    {
        $myrId = (int) Currency::query()->where('code', 'MYR')->value('id');

        // config 帶 swift → 寫入
        $this->seederWithEntries([
            ['code' => 'MYR_MAYBANK', 'name' => 'Maybank', 'currency' => 'MYR', 'aliases' => [], 'swift' => 'MBBEMYKL'],
        ])->run();
        $this->assertDatabaseHas('standard_banks', ['code' => 'MYR_MAYBANK', 'currency_id' => $myrId, 'swift' => 'MBBEMYKL']);

        // config 無 swift → 不清空既有值（curation 前的保護）
        $this->seederWithEntries([
            ['code' => 'MYR_MAYBANK', 'name' => 'Maybank', 'currency' => 'MYR', 'aliases' => []],
        ])->run();
        $this->assertDatabaseHas('standard_banks', ['code' => 'MYR_MAYBANK', 'currency_id' => $myrId, 'swift' => 'MBBEMYKL']);
    }

    #[Group('rules/standard-bank-list#R-5')]
    public function test_does_not_clobber_manual_status(): void
    {
        (new SyncStandardBanksSeeder)->run();

        $myrId = (int) Currency::query()->where('code', 'MYR')->value('id');
        StandardBank::query()->where('code', 'MYR_MAYBANK')->where('currency_id', $myrId)->update(['status' => 0]);

        (new SyncStandardBanksSeeder)->run();

        $this->assertDatabaseHas('standard_banks', ['code' => 'MYR_MAYBANK', 'currency_id' => $myrId, 'status' => 0]);
    }

    #[Group('rules/standard-bank-list#R-6')]
    public function test_skips_entry_when_currency_missing(): void
    {
        // 刻意用非流通碼 ZZZ 而非真實幣別：測試庫殘留業務幣別主檔時（本地開發庫常見，
        // CI 的乾淨庫則 currencies 為空），拿 THB 這類真實碼會讓 seeder 正常寫入而非跳過，
        // 使本測試的成敗取決於測試庫既有內容。前置條件顯式斷言，失敗時訊息才指得出原因。
        $this->assertDatabaseMissing('currencies', ['code' => 'ZZZ']);

        $seeder = $this->seederWithEntries([
            ['code' => 'ZZZ_KBANK', 'name' => 'Kasikornbank', 'currency' => 'ZZZ', 'aliases' => []],
        ]);

        $changed = $seeder->run();

        $this->assertSame(0, $changed);
        $this->assertDatabaseMissing('standard_banks', ['code' => 'ZZZ_KBANK']);
    }

    #[Group('rules/standard-bank-list#R-19')]
    #[Group('rules/standard-bank-list#R-20')]
    public function test_retired_entry_is_deactivated_and_its_names_move_to_the_current_bank(): void
    {
        $idrId = (int) Currency::query()->where('code', 'IDR')->value('id');

        $this->seederWithEntries([
            ['code' => 'IDR_TESTNOW', 'name' => 'Test Now Bank', 'currency' => 'IDR', 'aliases' => ['Test Now']],
            ['code' => 'IDR_TESTOTHER', 'name' => 'Test Other Bank', 'currency' => 'IDR', 'aliases' => ['Test Shared']],
            [
                'code' => 'IDR_TESTOLD', 'name' => 'Test Old Bank', 'currency' => 'IDR',
                'standard_name' => '(IDR) - Test Old Bank Full', 'aliases' => ['Test Old', 'Test Shared'],
                'retired_into' => 'IDR_TESTNOW',
            ],
        ])->run();

        $old = StandardBank::query()->where('code', 'IDR_TESTOLD')->where('currency_id', $idrId)->first();
        $now = StandardBank::query()->where('code', 'IDR_TESTNOW')->where('currency_id', $idrId)->first();

        $this->assertSame(0, (int) $old->status, '已併入別家的條目要設成停用');
        $this->assertSame(['Test Now', 'Test Old Bank', 'Test Old Bank Full', 'Test Old'], $now->aliases);
        $this->assertSame(1, (int) $now->status);
        // 「Test Shared」已是另一家現行條目的別名，不會被併進來，否則同一名稱兩個擁有者、兩邊都對不到
        $this->assertNotContains('Test Shared', $now->aliases);
    }

    #[Group('rules/standard-bank-list#R-24')]
    public function test_db_only_entry_takes_its_names_from_other_rows(): void
    {
        $idrId = (int) Currency::query()->where('code', 'IDR')->value('id');

        $this->seederWithEntries([
            ['code' => 'IDR_TESTBPD', 'name' => 'Test BPD', 'currency' => 'IDR', 'aliases' => ['Test BPD', 'Test BPD UUS']],
            ['code' => 'IDR_TESTBPDUUS', 'name' => 'Test BPD UUS', 'currency' => 'IDR', 'aliases' => ['Test BPD UUS'], 'sync_visible' => false],
        ])->run();

        $aliasesOf = fn (string $code): array => StandardBank::query()
            ->where('code', $code)
            ->where('currency_id', $idrId)
            ->firstOrFail()
            ->aliases;

        $this->assertSame(['Test BPD'], $aliasesOf('IDR_TESTBPD'));
        $this->assertSame(['Test BPD UUS'], $aliasesOf('IDR_TESTBPDUUS'));
    }

    public function test_writes_type_from_config_and_defaults_to_bank(): void
    {
        $myrId = (int) Currency::query()->where('code', 'MYR')->value('id');

        $this->seederWithEntries([
            ['code' => 'MYR_TESTWALLET', 'name' => 'Test Wallet', 'currency' => 'MYR', 'aliases' => [], 'type' => StandardBankTypeEnum::E_WALLET->value],
            ['code' => 'MYR_TESTBANKX', 'name' => 'Test Bank X', 'currency' => 'MYR', 'aliases' => []],
        ])->run();

        $this->assertDatabaseHas('standard_banks', ['code' => 'MYR_TESTWALLET', 'currency_id' => $myrId, 'type' => StandardBankTypeEnum::E_WALLET->value]);
        $this->assertDatabaseHas('standard_banks', ['code' => 'MYR_TESTBANKX', 'currency_id' => $myrId, 'type' => StandardBankTypeEnum::BANK->value]);
    }
}
