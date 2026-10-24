<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Currency;

use App\Enums\CurrencyEnum;
use App\Enums\StatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Currency;
use App\Models\EventVendorInfo;
use App\Models\VendorInfo;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * currency:backfill-vendor-info 的行為測試。
 *
 * 🔴 不要在這裡加 RefreshDatabase（會 migrate:fresh 清掉共用測試庫）。父類已 use
 *    DatabaseTransactions，每個測試結束自動回滾。
 *
 * CI 的 currencies 是空表（migrate:fresh 不灌幣別），setUp 先灌 currencies.php；
 * 「主檔缺列」的負向測試刻意刪主檔單一列來重現，同樣靠回滾復原。
 *
 * 回填只動 currency_id = 0 的列，所以每支測試只斷言自己建立的那幾列；
 * 退出碼取決於全表有沒有「啟用中且對不到」的列，測試庫殘留資料理論上會影響，
 * 因此斷言退出碼的測試都自己建一列啟用中對不到的資料來觸發、或只斷言成功路徑。
 *
 * php artisan test tests/Feature/Console/Commands/Currency/BackfillVendorInfoCurrencyCommandTest.php
 */
class BackfillVendorInfoCurrencyCommandTest extends TestCase
{
    private const string COMMAND = 'currency:backfill-vendor-info';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);
        $eventVendorInfo = EventVendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id, 'dry-run 不可寫入 vendor_infos');
        $this->assertSame(0, $eventVendorInfo->fresh()->currency_id, 'dry-run 不可寫入 event_vendor_infos');
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_execute_backfills_standard_currency_on_both_tables(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);
        $eventVendorInfo = EventVendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(CurrencyEnum::MYR->value, $vendorInfo->fresh()->currency_id);
        $this->assertSame(CurrencyEnum::MYR->value, $eventVendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-17')]
    public function test_unit_variant_backfills_to_standard_currency(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'KVND', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(
            CurrencyEnum::VND->value,
            $vendorInfo->fresh()->currency_id,
            'KVND 必須回填為 VND(3)，不可填 KVND(5)',
        );
    }

    #[Group('rules/currency-and-wallet-currency#R-17')]
    public function test_spelling_variant_backfills_after_normalization(): void
    {
        // 決議㉔：HKB 是 HKD 的拼寫變體，字串不動、currency_id 對到 HKD(16)。
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'HKB', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(CurrencyEnum::HKD->value, $vendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-17')]
    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_unresolvable_active_row_is_skipped_and_fails_the_run(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'MSC',
            'currency_id' => 0,
            'status' => StatusEnum::ENABLE->value,
        ]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('MSC')
            ->assertExitCode(1);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id, '對不到的代碼必須跳過，不可猜測填值');
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_unresolvable_disabled_row_is_skipped_without_failing_the_run(): void
    {
        // 決議㉔：MSC 商戶已停用，不阻斷回填；列出來但退出碼不因它轉紅（與稽核命令同口徑）。
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'MSC',
            'currency_id' => 0,
            'status' => StatusEnum::DISABLE->value,
        ]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('MSC')
            ->assertExitCode(0);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-17')]
    public function test_enum_case_without_master_row_is_skipped_not_written(): void
    {
        // 2026-09-07 六站生產的狀態：CurrencyEnum 已有 PHP(22)，主檔那一列要等 ops 跑 seeder。
        // 回填若只看 Enum 會寫出指不到主檔的孤兒 id（該欄無外鍵）。刪主檔單一列重現。
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->delete();
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'PHP',
            'currency_id' => 0,
            'status' => StatusEnum::ENABLE->value,
        ]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('enum_only')
            ->assertExitCode(1);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_already_filled_row_is_not_rewritten(): void
    {
        // 已填值的列不在回填範圍，即使字串對到別的幣別也不「修正」——那是稽核的事，不是回填的事。
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'MYR',
            'currency_id' => CurrencyEnum::THB->value,
        ]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(CurrencyEnum::THB->value, $vendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_backfill_keeps_updated_at_untouched(): void
    {
        // 兩張表的 updated_at 都是 ON UPDATE CURRENT_TIMESTAMP：不明寫保留原值，回填會把
        // 全站商戶的「最後異動時間」推到同一秒，後台與稽核以它判「近期改過」會整批假陽性。
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);
        VendorInfo::query()->toBase()->where('id', $vendorInfo->id)->update(['updated_at' => '2020-01-02 03:04:05']);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $fresh = $vendorInfo->fresh();
        $this->assertSame(CurrencyEnum::MYR->value, $fresh->currency_id);
        $this->assertSame('2020-01-02 03:04:05', $fresh->updated_at->format('Y-m-d H:i:s'));
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_table_option_restricts_the_run_to_that_table(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);
        $eventVendorInfo = EventVendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--table' => 'vendor_infos'])
            ->assertExitCode(0);

        $this->assertSame(CurrencyEnum::MYR->value, $vendorInfo->fresh()->currency_id);
        $this->assertSame(0, $eventVendorInfo->fresh()->currency_id, '--table 指定 vendor_infos 時不可動 event_vendor_infos');
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_unknown_table_option_is_rejected(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--table' => 'members'])
            ->expectsOutputToContain('members')
            ->assertExitCode(1);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id);
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_vendor_id_option_restricts_rows_to_that_vendor(): void
    {
        $target = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::AG->value,
            'currency' => 'MYR',
            'currency_id' => 0,
        ]);
        $other = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::PP->value,
            'currency' => 'MYR',
            'currency_id' => 0,
        ]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--vendor-id' => (string) VendorIdEnum::AG->value])
            ->assertExitCode(0);

        $this->assertSame(CurrencyEnum::MYR->value, $target->fresh()->currency_id);
        $this->assertSame(0, $other->fresh()->currency_id, '--vendor-id 之外的商戶不可被回填');
    }

    #[Group('rules/currency-and-wallet-currency#R-16')]
    public function test_every_written_row_is_logged(): void
    {
        $log = Log::spy();
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->assertExitCode(0);

        $log->shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $context = []): bool => ($context['table'] ?? null) === 'vendor_infos'
                && ($context['id'] ?? null) === $vendorInfo->id
                && ($context['currency'] ?? null) === 'MYR'
                && ($context['currency_id'] ?? null) === CurrencyEnum::MYR->value)
            ->once();
    }

    public function test_missing_currency_id_column_is_reported_before_any_query(): void
    {
        // 2026-09-07 六站生產的狀態：加欄 migration 還沒部署，直接查會炸 QueryException。
        // 命令要先查欄位存在、給 ops 看得懂的訊息，而不是 stack trace。
        // 測試庫不能真的 DROP COLUMN（DDL 隱式 commit），改以 Schema 的 partial mock 模擬缺欄。
        Schema::partialMock()
            ->shouldReceive('hasColumn')
            ->with('vendor_infos', 'currency_id')
            ->andReturn(false);

        $this->artisan(self::COMMAND, ['--table' => 'vendor_infos'])
            ->expectsOutputToContain('currency_id')
            ->assertExitCode(1);
    }
}
