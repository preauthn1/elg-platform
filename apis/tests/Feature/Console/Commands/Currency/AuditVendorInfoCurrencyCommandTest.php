<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Currency;

use App\Enums\CurrencyEnum;
use App\Enums\StatusEnum;
use App\Models\Currency;
use App\Models\VendorInfo;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * currency:audit-vendor-info 的行為測試。
 *
 * 🔴 不要在這裡加 RefreshDatabase：它對 MySQL 測試庫會跑 migrate:fresh，
 *    無條件 drop 全部資料表重建，procedure_hashes 會被清空、procedure 測試全紅，
 *    在 CI 上還會依 paratest 的分配變成隨機紅。父類 Tests\TestCase 已 use
 *    DatabaseTransactions，每個測試結束自動回滾，這裡不需要額外的資料庫重整。
 *
 * ⚠️ 退出碼是「全表」判定，會被測試庫裡任何殘留的 vendor_infos 列影響。
 *    因此只有兩支專測退出碼的測試斷言它，其餘一律只斷言自己建立的那一列，
 *    避免整組測試耦合到全域狀態。
 *
 * 稽核自 2026-09-07 起要求 Enum 的 id 在 currencies 主檔真的有那一列，而 CI 的 currencies
 * 是空表（migrate:fresh 不灌幣別），所以 setUp 先把 currencies.php 灌進去，測試結束由
 * DatabaseTransactions 回滾。兩支負向測試（enum_only／id_mismatch）刻意動主檔單一列來重現
 * 「版控已補 case、目標庫 seeder 還沒跑」的狀態，同樣靠回滾復原。
 *
 * php artisan test tests/Feature/Console/Commands/Currency/AuditVendorInfoCurrencyCommandTest.php
 */
class AuditVendorInfoCurrencyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    public function test_standard_currency_is_mapped(): void
    {
        VendorInfo::factory()->create(['currency' => 'MYR', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'MYR');

        $this->assertSame('mapped', $row['status']);
        $this->assertSame(1, $row['currency_id']);
    }

    #[Group('rules/currency-and-wallet-currency#R-5')]
    public function test_unit_variant_keeps_its_string_but_maps_to_standard_currency_id(): void
    {
        // 決議三：KVND 走字串欄位、currency_id 維持 VND(3)。
        // 千分制的尺度差由 vendor_infos.rate 吸收，不靠變體 id 表達。
        VendorInfo::factory()->create(['currency' => 'KVND', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'KVND');

        $this->assertSame('VND', $row['resolved']);
        $this->assertSame(3, $row['currency_id']);
        $this->assertSame('mapped', $row['status']);
    }

    public function test_case_variants_are_merged_into_one_group(): void
    {
        // 記錄當前行為：分組交給資料庫做，依預設 collation，kIDR 與 KIDR
        // 會被併成同一組。這對本命令的判定沒有影響——兩者經正規化後都對到
        // IDR、回填結果一致。
        //
        // ⚠️ 但也代表本命令看不出資料裡有哪些髒寫法。找出並修正髒寫法是第二期
        //    正規化作業的職責，修完之後後台輸入端也要補驗證，否則會再進來。
        VendorInfo::factory()->create(['currency' => 'KIDR', 'status' => StatusEnum::ENABLE->value]);
        VendorInfo::factory()->create(['currency' => 'kIDR', 'status' => StatusEnum::DISABLE->value]);

        // 合併後保留哪一種寫法由資料庫決定，所以用大小寫不敏感的方式取這一組。
        $matched = array_values(array_filter(
            $this->audit()['rows'],
            static fn (array $row): bool => strcasecmp((string) $row['raw'], 'KIDR') === 0,
        ));

        $this->assertCount(1, $matched, '大小寫不同的寫法應被資料庫分組合併成一列');
        $this->assertSame(2, $matched[0]['total']);
        $this->assertSame(1, $matched[0]['active']);
        $this->assertSame('IDR', $matched[0]['resolved']);
        $this->assertSame(6, $matched[0]['currency_id']);
    }

    public function test_unresolved_code_is_reported_without_currency_id(): void
    {
        // 2026-09-07 起 HKB 已裁決為 HKD 拼寫變體，對照表裡仍留在 unresolved 的只剩 MSC。
        VendorInfo::factory()->create(['currency' => 'MSC', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'MSC');

        $this->assertSame('unresolved', $row['status']);
        $this->assertNull($row['currency_id']);
    }

    public function test_spelling_variant_normalizes_to_standard_currency(): void
    {
        // RB 2026-09-07 裁決：HKB 是 HKD 的拼寫變體（rate 1、組織幣別 HKD），走 normalize 而非 unit_variant。
        // 字串本身不動（它進對外 API），只讓 currency_id 對到 HKD(16)。
        VendorInfo::factory()->create(['currency' => 'HKB', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'HKB');

        $this->assertSame('HKD', $row['resolved']);
        $this->assertSame(CurrencyEnum::HKD->value, $row['currency_id']);
        $this->assertSame('mapped', $row['status']);
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_non_currency_code_is_labelled_but_still_has_an_id(): void
    {
        // FON 是站台點數不是幣別，但它在 CurrencyEnum 有 id(11)、主檔也有那一列，
        // 回填 currency_id 不會落空，所以標示分類但不算阻斷。
        VendorInfo::factory()->create(['currency' => 'FON', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'FON');

        $this->assertSame('non_currency', $row['status']);
        $this->assertSame(11, $row['currency_id']);
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_crypto_code_is_labelled_separately_from_fiat(): void
    {
        // crypto 分類只是稽核輸出的標籤：落在這一類的代碼仍帶得出自己的 currency_id。
        // 決議㉚（2026-09-17）起虛擬貨幣可作為錢包幣別，TON 不錨定美元也不影響——它是獨立幣別，不對應 USD。
        VendorInfo::factory()->create(['currency' => 'TON', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'TON');

        $this->assertSame('crypto', $row['status']);
        $this->assertSame(CurrencyEnum::TON->value, $row['currency_id']);
    }

    #[Group('rules/currency-and-wallet-currency#R-4')]
    public function test_usdt_resolves_to_its_own_currency_id(): void
    {
        // RB 2026-09-17 裁決（決議㉚，撤回決議㉖）：USDT 是獨立幣別，不對應 USD。
        // 字串不經改寫，currency_id 對到 USDT 自己（不是 USD 23），狀態標為 crypto。
        VendorInfo::factory()->create(['currency' => 'USDT', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'USDT');

        $this->assertSame('USDT', $row['resolved']);
        $this->assertSame(CurrencyEnum::USDT->value, $row['currency_id']);
        $this->assertSame('crypto', $row['status']);
    }

    public function test_enum_backed_code_without_master_row_is_blocked(): void
    {
        // 這正是 2026-09-07 六站生產的狀態：CurrencyEnum 已有 PHP(22)，主檔那一列要等 ops 跑 seeder 才有。
        // 稽核若只看 Enum 就會對這種站開綠燈，回填出指不到主檔的孤兒 currency_id。
        // 刪掉主檔那一列來重現（單列、DatabaseTransactions 回滾）。
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->delete();
        VendorInfo::factory()->create(['currency' => 'PHP', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'PHP');

        $this->assertSame('enum_only', $row['status']);
        $this->assertNull($row['currency_id']);
    }

    public function test_master_row_with_different_code_at_enum_id_is_blocked(): void
    {
        // 主檔 id 24 若被別的代碼佔走（例如後台手建），CNY 在 Enum 有 id、主檔卻對不上，
        // 照 Enum 回填會把 CNY 商戶指到別的幣別。改掉主檔那一列的 code 來重現（單列、回滾）。
        Currency::query()->whereKey(CurrencyEnum::CNY->value)->update(['code' => 'ZZC']);
        VendorInfo::factory()->create(['currency' => 'CNY', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'CNY');

        $this->assertSame('id_mismatch', $row['status']);
        $this->assertNull($row['currency_id']);
    }

    public function test_currency_missing_from_master_data_is_pending_seed(): void
    {
        // BND 有商戶在用（VWorld 18 筆、全停用），但 currencies 主檔與 CurrencyEnum 都查無，回填會落空。
        // 原例用 USD，2026-09-07 第二期 2.1 把 USD 補進主檔後改用仍在 pending_standard 的 BND。
        VendorInfo::factory()->create(['currency' => 'BND', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'BND');

        $this->assertSame('pending_seed', $row['status']);
        $this->assertNull($row['currency_id']);
    }

    public function test_unrecognised_code_falls_back_to_unknown(): void
    {
        // 對照表完全沒收錄的新髒資料——這支稽核最主要的價值就在攔下這種。
        VendorInfo::factory()->create(['currency' => 'ZZZ', 'status' => StatusEnum::ENABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'ZZZ');

        $this->assertSame('unknown', $row['status']);
        $this->assertNull($row['currency_id']);
    }

    public function test_counts_aggregate_rows_sharing_the_same_code(): void
    {
        VendorInfo::factory()->count(2)->create(['currency' => 'THB', 'status' => StatusEnum::ENABLE->value]);
        VendorInfo::factory()->create(['currency' => 'THB', 'status' => StatusEnum::DISABLE->value]);

        $row = $this->rowFor($this->audit()['rows'], 'THB');

        $this->assertSame(3, $row['total']);
        $this->assertSame(2, $row['active']);
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_exit_code_is_zero_when_every_active_vendor_maps(): void
    {
        VendorInfo::factory()->create(['currency' => 'MYR', 'status' => StatusEnum::ENABLE->value]);

        $this->assertSame(0, $this->auditExitCode());
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_exit_code_is_one_when_an_active_vendor_cannot_map(): void
    {
        VendorInfo::factory()->create(['currency' => 'MSC', 'status' => StatusEnum::ENABLE->value]);

        $this->assertSame(1, $this->auditExitCode());
    }

    #[Group('rules/currency-and-wallet-currency#R-18')]
    public function test_disabled_vendor_with_unmappable_code_does_not_block(): void
    {
        // 停用中的商戶不擋閘門，但它的 currency_id 一樣回填不到，
        // 所以要出現在 unmappable_rows_total，只看退出碼會漏掉這批。
        VendorInfo::factory()->count(2)->create(['currency' => 'MSC', 'status' => StatusEnum::DISABLE->value]);

        $payload = $this->audit();

        $this->assertSame(0, $payload['blocked_codes']);
        $this->assertSame(2, $payload['unmappable_rows_total']);
    }

    public function test_blocked_codes_counts_codes_not_rows(): void
    {
        VendorInfo::factory()->count(2)->create(['currency' => 'MSC', 'status' => StatusEnum::ENABLE->value]);
        VendorInfo::factory()->count(3)->create(['currency' => 'BND', 'status' => StatusEnum::ENABLE->value]);

        $payload = $this->audit();

        // 兩種代碼、五列 —— blocked_codes 數的是代碼種數。（USD 已進主檔，改用 BND 當第二種擋單代碼）
        $this->assertSame(2, $payload['blocked_codes']);
        $this->assertSame(5, $payload['unmappable_rows_total']);
    }

    public function test_table_output_reports_success(): void
    {
        VendorInfo::factory()->create(['currency' => 'MYR', 'status' => StatusEnum::ENABLE->value]);

        $this->artisan('currency:audit-vendor-info')
            ->expectsOutputToContain('所有啟用中商戶的幣別代碼都能對到 currency_id')
            ->assertSuccessful();
    }

    public function test_table_output_reports_failure(): void
    {
        VendorInfo::factory()->create(['currency' => 'MSC', 'status' => StatusEnum::ENABLE->value]);

        $this->artisan('currency:audit-vendor-info')
            ->expectsOutputToContain('後續回填會落空')
            ->assertFailed();
    }

    /**
     * 跑稽核並取回 JSON 輸出。
     *
     * @return array<string, mixed>
     */
    private function audit(): array
    {
        Artisan::call('currency:audit-vendor-info', ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 跑稽核並只取退出碼。
     */
    private function auditExitCode(): int
    {
        return Artisan::call('currency:audit-vendor-info', ['--json' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $raw): array
    {
        foreach ($rows as $row) {
            // 刻意用大小寫敏感的比對：kIDR 與 KIDR 必須被視為兩列。
            if ($row['raw'] === $raw) {
                return $row;
            }
        }

        $this->fail(sprintf(
            '稽核輸出裡找不到幣別代碼「%s」，實際有：%s',
            $raw,
            implode('、', array_column($rows, 'raw')),
        ));
    }
}
