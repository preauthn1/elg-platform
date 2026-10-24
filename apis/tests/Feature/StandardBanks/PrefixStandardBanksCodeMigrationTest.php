<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * `2026_08_28_000001_prefix_standard_banks_code_with_currency`＝把 standard_banks.code
 * 機械補上幣別前綴（MAYBANK → MYR_MAYBANK），使 DB 與 config 的 `{幣別}_{名稱}` 同格式、
 * SyncStandardBanksSeeder 的 upsert 鍵 (code, currency_id) 兩側對得上。
 *
 * ⚠️ 刻意不用 DatabaseTransactions：migration 尾端的 ALTER TABLE 是 DDL，MySQL 會隱式
 * 提交，包在交易裡只是假的安全感。改為自行以高位測試 id 隔離並在 setUp／tearDown 明確清除。
 */
class PrefixStandardBanksCodeMigrationTest extends TestCase
{
    /** 測試專用幣別 id（高位，避開既有資料）。 */
    private const CURRENCY_ID = 99840;

    /** 刻意不建對應 currencies 列，用來觸發孤兒斷言。 */
    private const ORPHAN_CURRENCY_ID = 99841;

    private const CURRENCY_CODE = 'TS9';

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require base_path(
            'database/migrations/2026_08_28_000001_prefix_standard_banks_code_with_currency.php'
        );

        $this->purge();

        DB::table('currencies')->insert([
            'id' => self::CURRENCY_ID, 'code' => self::CURRENCY_CODE,
            'name' => 'Test Currency 9', 'rate' => 1, 'admin' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        DB::table('standard_banks')
            ->whereIn('currency_id', [self::CURRENCY_ID, self::ORPHAN_CURRENCY_ID])
            ->delete();
        DB::table('currencies')->where('id', self::CURRENCY_ID)->delete();
    }

    private function seedStandardBank(string $code, ?string $currencyCode = null, ?int $currencyId = null): int
    {
        return (int) DB::table('standard_banks')->insertGetId([
            'code' => $code,
            'currency_id' => $currencyId ?? self::CURRENCY_ID,
            'currency_code' => $currencyCode ?? '',
            'type' => 1,
            'name' => 'Bank ' . $code,
            'status' => 1,
        ]);
    }

    private function codeOf(int $id): string
    {
        return (string) DB::table('standard_banks')->where('id', $id)->value('code');
    }

    #[Group('rules/standard-bank-list#R-2')]
    public function test_prefixes_code_and_backfills_currency_code(): void
    {
        $id = $this->seedStandardBank('MAYBANK');

        $this->migration->up();

        $row = DB::table('standard_banks')->where('id', $id)->first();
        $this->assertSame('TS9_MAYBANK', $row->code);
        $this->assertSame(self::CURRENCY_CODE, $row->currency_code, '前綴與 currency_code 互為校驗，兩者須同時就緒');
    }

    /** 冪等：重跑不該把前綴疊成 TS9_TS9_XXX。 */
    #[Group('rules/standard-bank-list#R-2')]
    public function test_does_not_double_prefix_on_rerun(): void
    {
        $id = $this->seedStandardBank('TS9_ALREADY', self::CURRENCY_CODE);

        $this->migration->up();
        $this->migration->up();

        $this->assertSame('TS9_ALREADY', $this->codeOf($id));
    }

    /**
     * 補前綴後會與既有列撞 (code, currency_id) 時必須整批中止且零寫入——
     * 若讓它寫下去，兩列會塌成同一個 upsert 鍵，seeder 之後只認得其中一列。
     */
    #[Group('rules/standard-bank-list#R-3')]
    public function test_aborts_without_writing_when_prefixing_would_collide(): void
    {
        $bare = $this->seedStandardBank('DUP');
        $prefixed = $this->seedStandardBank('TS9_DUP', self::CURRENCY_CODE);

        try {
            $this->migration->up();
            $this->fail('補前綴會撞既有列時必須拋出例外');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $bare, $e->getMessage(), '例外訊息要指出待人工處理的 id');
        }

        $this->assertSame('DUP', $this->codeOf($bare), '中止時不得留下半套寫入');
        $this->assertSame('TS9_DUP', $this->codeOf($prefixed));
    }

    /** currency_id 對不到 currencies 的列不會被 UPDATE 觸及，必須 fail loud 而非留下前綴不一致的暗雷。 */
    public function test_fails_loud_on_rows_whose_currency_is_missing(): void
    {
        $orphan = $this->seedStandardBank('ORPHAN', null, self::ORPHAN_CURRENCY_ID);

        try {
            $this->migration->up();
            $this->fail('currency_id 孤兒列必須讓 migration 失敗');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $orphan, $e->getMessage());
        }
    }

    public function test_down_strips_prefix(): void
    {
        $id = $this->seedStandardBank('MAYBANK');

        $this->migration->up();
        $this->assertSame('TS9_MAYBANK', $this->codeOf($id));

        $this->migration->down();
        $this->assertSame('MAYBANK', $this->codeOf($id));
    }
}
