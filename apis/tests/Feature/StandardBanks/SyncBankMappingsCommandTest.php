<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Bank;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SyncBankMappingsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private int $currencyId;

    private int $stdId;

    private int $stdId2;

    protected function setUp(): void
    {
        parent::setUp();

        // currencies 的 rate / admin 是 NOT NULL 無預設；用 DB::table 直插以繞過 model $guarded
        // 使用 TST 測試幣別，避免與既存 standard_banks(code, currency_id) 唯一鍵衝突
        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TST',
            'name' => 'Test Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $this->stdId = StandardBank::query()->create([
            'code' => 'TESTBANK',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TST',
            'type' => 1,
            'name' => 'Test Bank',
            'aliases' => ['Testbank', 'TB'],
            'status' => 1,
        ])->id;

        // 第二個 standard_bank（不同 code），供 B 歧義 tie-break 測試對到不同 std
        $this->stdId2 = StandardBank::query()->create([
            'code' => 'TESTBANK2',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TST',
            'type' => 1,
            'name' => 'Test Bank 2',
            'aliases' => ['Testbank2'],
            'status' => 1,
        ])->id;
    }

    private function makeBank(int $group, string $code, string $name): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $group,
            'currency_id' => $this->currencyId,
            'type' => 1,
            'code' => $code,
            'name' => $name,
            'status' => 1,
        ]);
    }

    private function softDelete(Bank $bank): void
    {
        // Bank model 未掛 SoftDeletes trait，deleted_at 為一般欄位，直接寫入。
        DB::table('banks')->where('id', $bank->id)->update(['deleted_at' => now()]);
    }

    public function test_group0_maps_by_currency_and_code(): void
    {
        $bank = $this->makeBank(0, 'TESTBANK', 'Test Bank');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertSame($this->stdId, $bank->fresh()->standard_bank_id);
    }

    public function test_group_positive_inherits_via_group0_by_code(): void
    {
        $this->makeBank(0, 'TESTBANK', 'Test Bank');
        $child = $this->makeBank(7, 'TESTBANK', 'Test Bank');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertSame($this->stdId, $child->fresh()->standard_bank_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $bank = $this->makeBank(0, 'TESTBANK', 'Test Bank');
        $this->artisan('bank:sync-mappings', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($bank->fresh()->standard_bank_id);
    }

    // D-1：name-hop 正向 —— child(group>0) 的 code 與所有 group=0 皆不同、但 name 相同 → 繼承 base 的 std
    public function test_group_positive_inherits_via_group0_by_name(): void
    {
        $this->makeBank(0, 'TESTBANK', 'Test Bank');
        // child code 與 base 不同（避免 code-hop 命中），name 相同 → 只能靠 name-hop
        $child = $this->makeBank(7, 'DIFFERENTCODE', 'Test Bank');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertSame($this->stdId, $child->fresh()->standard_bank_id);
    }

    // D-2：負向 —— child 的 code 與 name 皆無 match → 保持 NULL
    public function test_group_positive_no_match_stays_null(): void
    {
        $this->makeBank(0, 'TESTBANK', 'Test Bank');
        $child = $this->makeBank(7, 'NOPE', 'No Such Bank');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertNull($child->fresh()->standard_bank_id);
    }

    // D-3：B 歧義 tie-break —— 兩筆 group=0 同 (currency_id,name) 不同 code 對到不同 std
    //      → 該 name 的 group>0 child 不被回填（NULL、計殘餘）
    public function test_name_hop_skips_when_ambiguous_standard_bank(): void
    {
        // 兩個 group=0 base 同 name「Ambiguous」，但 code 分別對到 stdId / stdId2
        $this->makeBank(0, 'TESTBANK', 'Ambiguous');
        $this->makeBank(0, 'TESTBANK2', 'Ambiguous');
        // child code 與兩個 base 皆不同（排除 code-hop），只剩歧義的 name-hop
        $child = $this->makeBank(7, 'CHILDCODE', 'Ambiguous');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertNull($child->fresh()->standard_bank_id);
    }

    // D-4：C 軟刪除 —— 唯一 name-match 的 group=0 base 被軟刪除 → child 不繼承（NULL）
    // 名稱刻意取「非 standard name/alias」（Legacy Local Name），隔離 tier3 直配、單測 name-hop 路徑
    public function test_name_hop_ignores_soft_deleted_base(): void
    {
        $base = $this->makeBank(0, 'TESTBANK', 'Legacy Local Name');
        $this->softDelete($base);
        $child = $this->makeBank(7, 'DIFFERENTCODE', 'Legacy Local Name');
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $this->assertNull($child->fresh()->standard_bank_id);
    }

    // D-5：A preview == 實寫 —— dry-run 印出的可回填數（table 兩列相加）== 實跑實際被回填的列數
    public function test_dry_run_count_equals_actual_filled_rows(): void
    {
        // group=0 base：一筆 code-match 主檔
        $this->makeBank(0, 'TESTBANK', 'Test Bank');
        // group>0 children：一筆 code-hop、一筆 name-hop、一筆 code+name 同時命中（不可重複計）、一筆完全 no-match
        $this->makeBank(7, 'TESTBANK', 'Whatever');       // code-hop
        $this->makeBank(8, 'OTHERCODE', 'Test Bank');     // name-hop
        $this->makeBank(9, 'TESTBANK', 'Test Bank');      // code 與 name 皆命中 → 只算一次
        $this->makeBank(10, 'NOPE', 'No Match');          // no-match

        $beforeNull = DB::table('banks')->whereNull('standard_bank_id')->whereNull('deleted_at')->count();

        // 取 dry-run 報的可回填數：解析 table 內「純數字」的 cell（label cell 帶文字，會被過濾掉）
        Artisan::call('bank:sync-mappings', ['--dry-run' => true]);
        $previewedTotal = $this->parsePreviewTotal(Artisan::output());

        // dry-run 不得寫入
        $this->assertSame(
            $beforeNull,
            DB::table('banks')->whereNull('standard_bank_id')->whereNull('deleted_at')->count(),
        );

        // 實跑並計算實際被回填列數
        $this->artisan('bank:sync-mappings')->assertSuccessful();
        $afterNull = DB::table('banks')->whereNull('standard_bank_id')->whereNull('deleted_at')->count();
        $actualFilled = $beforeNull - $afterNull;

        // group=0(1) + group>0 code-hop(1) + name-hop(1) + code&name 同筆(1) = 4；no-match 留 NULL
        $this->assertSame(4, $actualFilled);
        // 核心不變式：dry-run 印出的可回填數 == 實際被回填列數
        $this->assertSame($actualFilled, $previewedTotal);
    }

    #[Group('rules/standard-bank-list#R-12')]
    public function test_repair_dry_run_reports_without_writing(): void
    {
        $bank = $this->makeBank(0, 'LOCAL20', 'Test Bank');
        DB::table('banks')->where('id', $bank->id)->update(['standard_bank_id' => $this->stdId2]);

        $this->artisan('bank:sync-mappings', ['--repair' => true, '--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->stdId2, $bank->fresh()->standard_bank_id);
    }

    #[Group('rules/standard-bank-list#R-12')]
    public function test_repair_corrects_stale_mapping_and_leaves_unmapped_rows_to_sync(): void
    {
        $stale = $this->makeBank(0, 'LOCAL21', 'Test Bank');
        DB::table('banks')->where('id', $stale->id)->update(['standard_bank_id' => $this->stdId2]);
        // code 與 standard 同名 → tier1 可回填；repair 是獨立模式，不該兼做回填
        $unmapped = $this->makeBank(0, 'TESTBANK', 'Test Bank');

        $this->artisan('bank:sync-mappings', ['--repair' => true])->assertSuccessful();

        $this->assertSame($this->stdId, $stale->fresh()->standard_bank_id);
        $this->assertNull($unmapped->fresh()->standard_bank_id, 'repair 模式不兼做回填');
    }

    /**
     * 從命令 table 輸出加總「純數字」cell（label cell 含文字被排除），得預覽可回填總數。
     */
    private function parsePreviewTotal(string $output): int
    {
        $total = 0;
        foreach (explode("\n", $output) as $line) {
            foreach (explode('|', $line) as $cell) {
                $cell = trim($cell);
                if ($cell !== '' && ctype_digit($cell)) {
                    $total += (int) $cell;
                }
            }
        }

        return $total;
    }
}
