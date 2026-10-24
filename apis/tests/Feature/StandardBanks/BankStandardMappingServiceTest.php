<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Bank;
use App\Models\StandardBank;
use App\Services\CashFlow\Bank\BankStandardMappingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class BankStandardMappingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $currencyId;

    private int $stdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TST',
            'name' => 'Test Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $this->stdId = $this->makeStandardBank('TESTBANK', 'Test Bank', ['Testbank Berhad', 'TB']);
    }

    /**
     * @param  list<string>  $aliases
     */
    private function makeStandardBank(string $code, string $name, array $aliases = []): int
    {
        return StandardBank::query()->create([
            'code' => $code,
            'currency_id' => $this->currencyId,
            'currency_code' => 'TST',
            'type' => 1,
            'name' => $name,
            'aliases' => $aliases,
            'status' => 1,
        ])->id;
    }

    private function makeBank(string $code, string $name, int $status = 1, int $group = 7): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $group,
            'currency_id' => $this->currencyId,
            'type' => 1,
            'code' => $code,
            'name' => $name,
            'status' => $status,
        ]);
    }

    #[Group('rules/standard-bank-list#R-10')]
    public function test_tier3_fills_by_prefixed_name_and_alias_with_audit(): void
    {
        // 名稱帶 (CUR) 前綴＝banks 命名慣例；code 與 standard 不同（tier1/2 對不上）
        $byName = $this->makeBank('LOCAL01', '(TST) - Test Bank');
        $byAlias = $this->makeBank('LOCAL02', 'Testbank Berhad');

        $filled = app(BankStandardMappingService::class)->fillTier3();

        $this->assertSame(2, $filled);
        $this->assertSame($this->stdId, $byName->fresh()->standard_bank_id);
        $this->assertSame($this->stdId, $byAlias->fresh()->standard_bank_id);
        $this->assertDatabaseHas('bank_mapping_logs', [
            'bank_id' => $byName->id, 'standard_bank_id' => $this->stdId,
            'tier' => 'alias', 'confidence' => 100, 'matched_by' => 'name', 'action' => 'auto_fill',
        ]);
        $this->assertDatabaseHas('bank_mapping_logs', [
            'bank_id' => $byAlias->id, 'confidence' => 95, 'matched_by' => 'alias',
        ]);
    }

    #[Group('rules/standard-bank-list#R-10')]
    #[Group('rules/standard-bank-list#R-11')]
    public function test_tier3_skips_ambiguous_alias_and_non_live_rows(): void
    {
        StandardBank::query()->create([
            'code' => 'TESTBANK2', 'currency_id' => $this->currencyId, 'currency_code' => 'TST',
            'type' => 1, 'name' => 'Test Bank Two', 'aliases' => ['TB'], 'status' => 1,
        ]);
        $ambiguous = $this->makeBank('LOCAL03', 'TB'); // alias 'TB' 對到兩個 standard → 歧義跳過
        $inactive = $this->makeBank('LOCAL04', '(TST) - Test Bank', status: 0); // 非存活列不回填

        app(BankStandardMappingService::class)->fillTier3();

        $this->assertNull($ambiguous->fresh()->standard_bank_id);
        $this->assertNull($inactive->fresh()->standard_bank_id);
    }

    #[Group('rules/standard-bank-list#R-11')]
    public function test_tier3_treats_case_variant_aliases_of_same_standard_as_one_owner(): void
    {
        // 分檔慣例常同時列 'VietABank' 與 'VIETABANK'；正規化後同鍵，不能被當成兩家爭用
        $stdId = $this->makeStandardBank('TESTBANK3', 'Test Bank Three', ['Testbank Three', 'TESTBANK THREE']);
        $bank = $this->makeBank('LOCAL05', '(TST) - Testbank Three');

        $filled = app(BankStandardMappingService::class)->fillTier3();

        $this->assertSame(1, $filled);
        $this->assertSame($stdId, $bank->fresh()->standard_bank_id);
    }

    public function test_review_candidates_ranked_by_confidence(): void
    {
        // 與 standard name 相近但非精確 → similarity 候選（<95 進人工佇列）
        $fuzzy = $this->makeBank('LOCAL05', '(TST) - Test Bankk');

        $rows = app(BankStandardMappingService::class)->reviewCandidates();

        $hit = collect($rows)->firstWhere('bank_id', $fuzzy->id);
        $this->assertNotNull($hit, '相似名稱應產生複核候選');
        $this->assertSame($this->stdId, $hit['candidate_standard_bank_id']);
        $this->assertLessThan(95, $hit['confidence']);
        $this->assertNull($fuzzy->fresh()->standard_bank_id, '複核候選不得自動回填');
    }

    public function test_resolve_on_create_matches_and_reports_ambiguity_as_null(): void
    {
        $service = app(BankStandardMappingService::class);

        $hit = $service->resolveOnCreate($this->currencyId, '(TST) - Test Bank');
        $this->assertSame($this->stdId, $hit['standard_bank_id']);
        $this->assertSame(100, $hit['confidence']);

        $aliasHit = $service->resolveOnCreate($this->currencyId, 'Testbank Berhad');
        $this->assertSame(95, $aliasHit['confidence']);

        $this->assertNull($service->resolveOnCreate($this->currencyId, 'No Such Bank'));
    }

    public function test_apply_reviewed_fills_valid_rows_and_skips_mapped_or_missing(): void
    {
        $target = $this->makeBank('LOCAL06', 'Some Name');
        $alreadyMapped = $this->makeBank('LOCAL07', 'Other Name');
        DB::table('banks')->where('id', $alreadyMapped->id)->update(['standard_bank_id' => $this->stdId]);

        $result = app(BankStandardMappingService::class)->applyReviewed([
            ['bank_id' => $target->id, 'standard_bank_id' => $this->stdId, 'confidence' => 80],
            ['bank_id' => $alreadyMapped->id, 'standard_bank_id' => $this->stdId, 'confidence' => 80],
            ['bank_id' => $target->id, 'standard_bank_id' => 999999, 'confidence' => 80],
        ]);

        $this->assertSame(1, $result['applied']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame($this->stdId, $target->fresh()->standard_bank_id);
        $this->assertDatabaseHas('bank_mapping_logs', [
            'bank_id' => $target->id, 'tier' => 'reviewed', 'matched_by' => 'manual', 'action' => 'reviewed_fill',
        ]);
    }

    #[Group('rules/standard-bank-list#R-12')]
    public function test_repair_corrects_stale_mapping_with_audit(): void
    {
        // 舊 registry 沒有細分條目時，這類列被回填成粗粒度母條目；registry 補上細分後既有值就成了錯的
        $stale = $this->makeStandardBank('OTHERBANK', 'Other Bank');
        $bank = $this->makeBank('LOCAL10', '(TST) - Test Bank');
        DB::table('banks')->where('id', $bank->id)->update(['standard_bank_id' => $stale]);

        $repaired = app(BankStandardMappingService::class)->repairMismatched();

        $this->assertSame(1, $repaired);
        $this->assertSame($this->stdId, $bank->fresh()->standard_bank_id);
        $this->assertDatabaseHas('bank_mapping_logs', [
            'bank_id' => $bank->id, 'standard_bank_id' => $this->stdId,
            'tier' => 'alias', 'confidence' => 100, 'matched_by' => 'name', 'action' => 'repair',
        ]);
    }

    public function test_repair_leaves_already_correct_rows_untouched(): void
    {
        $bank = $this->makeBank('LOCAL11', 'Testbank Berhad');
        DB::table('banks')->where('id', $bank->id)->update(['standard_bank_id' => $this->stdId]);

        $this->assertSame(0, app(BankStandardMappingService::class)->repairMismatched());
        $this->assertDatabaseMissing('bank_mapping_logs', ['bank_id' => $bank->id, 'action' => 'repair']);
    }

    #[Group('rules/standard-bank-list#R-12')]
    public function test_repair_keeps_existing_value_when_no_exact_match(): void
    {
        // 名稱對不上任何 standard ⇒ 無從判斷正確值，既有值一律保留：不猜、也不清空
        $stale = $this->makeStandardBank('OTHERBANK2', 'Other Bank Two');
        $bank = $this->makeBank('LOCAL12', 'Completely Unknown Bank');
        DB::table('banks')->where('id', $bank->id)->update(['standard_bank_id' => $stale]);

        $this->assertSame(0, app(BankStandardMappingService::class)->repairMismatched());
        $this->assertSame($stale, $bank->fresh()->standard_bank_id);
    }

    #[Group('rules/standard-bank-list#R-12')]
    public function test_repair_ignores_unmapped_and_non_live_rows(): void
    {
        $stale = $this->makeStandardBank('OTHERBANK3', 'Other Bank Three');
        $unmapped = $this->makeBank('LOCAL13', '(TST) - Test Bank');            // 仍為 null＝tier3 的守備範圍
        $inactive = $this->makeBank('LOCAL14', '(TST) - Test Bank', status: 0); // 非存活列不動
        DB::table('banks')->where('id', $inactive->id)->update(['standard_bank_id' => $stale]);

        $this->assertSame(0, app(BankStandardMappingService::class)->repairMismatched());
        $this->assertNull($unmapped->fresh()->standard_bank_id);
        $this->assertSame($stale, $inactive->fresh()->standard_bank_id);
    }

    public function test_repair_candidates_report_transition_without_writing(): void
    {
        $stale = $this->makeStandardBank('OTHERBANK4', 'Other Bank Four');
        $bank = $this->makeBank('LOCAL15', '(TST) - Test Bank');
        DB::table('banks')->where('id', $bank->id)->update(['standard_bank_id' => $stale]);

        $candidates = app(BankStandardMappingService::class)->repairCandidates();

        $hit = collect($candidates)->firstWhere('bank_id', $bank->id);
        $this->assertNotNull($hit);
        $this->assertSame($stale, $hit['from_standard_bank_id']);
        $this->assertSame($this->stdId, $hit['standard_bank_id']);
        $this->assertSame('name', $hit['matched_by']);
        $this->assertSame($stale, $bank->fresh()->standard_bank_id, '候選查詢必須唯讀');
    }
}
