<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * `bank:set-name`＝把指定 banks 列的 `name` 改成另一個名稱（單列改名）。
 *
 * 場景取自 USDT 提款銀行統一名稱：base 層與支付組別層要改成同一個名稱，
 * 否則建代付金流商戶時支付組別層會被 base 層的舊名稱整列蓋回。
 */
class SetBankNameCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99850;

    private const OTHER_AGENT_GROUP = 99851;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS9', 'name' => 'Test Currency 9', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $name, string $code, int $agentGroup = self::AGENT_GROUP, int $status = 1): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroup,
            'type' => 3,
            'currency_id' => $this->currencyId,
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function nameOf(Bank $bank): string
    {
        return (string) Bank::query()->whereKey($bank->id)->value('name');
    }

    private function auditCount(Bank $bank): int
    {
        return DB::table('bank_dedup_logs')
            ->where('action', 'set_bank_name')
            ->where('loser_bank_id', $bank->id)
            ->count();
    }

    #[Group('rules/standard-bank-list#R-28')]
    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $bank = $this->seedBank('TS9(TRC20)', 'TS9A');

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':TS9 - TRC20'],
            '--reason' => '統一 USDT 提款銀行名稱',
        ])->assertExitCode(0);

        $this->assertSame('TS9(TRC20)', $this->nameOf($bank));
        $this->assertSame(0, $this->auditCount($bank));
    }

    #[Group('rules/standard-bank-list#R-29')]
    public function test_renames_and_writes_audit(): void
    {
        $bank = $this->seedBank('TS9(TRC20)', 'TS9B');

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':TS9 - TRC20'],
            '--reason' => '統一 USDT 提款銀行名稱',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS9 - TRC20', $this->nameOf($bank));
        $this->assertSame('TS9B', (string) Bank::query()->whereKey($bank->id)->value('code'), '只改名稱，code 不動');

        $log = DB::table('bank_dedup_logs')
            ->where('action', 'set_bank_name')
            ->where('loser_bank_id', $bank->id)
            ->first();

        $this->assertNotNull($log, '不可逆寫入必須留審計');
        $this->assertSame('TS9B', $log->code);
        $this->assertSame($bank->id, (int) $log->survivor_bank_id);

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame('TS9(TRC20)', $detail['was']['name'], '還原靠 was.name');
        $this->assertSame('TS9 - TRC20', $detail['aft']['name']);
        $this->assertSame('統一 USDT 提款銀行名稱', $detail['reason']);
    }

    #[Group('rules/standard-bank-list#R-31')]
    public function test_skips_rows_already_named_target_without_audit(): void
    {
        $done = $this->seedBank('TS9 - TRC20', 'TS9C');
        $pending = $this->seedBank('TS9(TRC20)', 'TS9D', self::OTHER_AGENT_GROUP);

        $this->artisan('bank:set-name', [
            '--pair' => [$done->id . ':TS9 - TRC20', $pending->id . ':TS9 - TRC20'],
            '--reason' => '重跑同一批配對',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, $this->auditCount($done), '已是目標名稱的列不寫審計');
        $this->assertSame('TS9 - TRC20', $this->nameOf($pending));
        $this->assertSame(1, $this->auditCount($pending));
    }

    #[Group('rules/standard-bank-list#R-30')]
    public function test_blocks_whole_batch_when_active_namesake_exists_in_same_scope(): void
    {
        $this->seedBank('TS9 - TRC20', 'TS9E');
        $clash = $this->seedBank('TS9(TRC20)', 'TS9F');
        $innocent = $this->seedBank('TS9(ERC20)', 'TS9G');

        $this->artisan('bank:set-name', [
            '--pair' => [$clash->id . ':TS9 - TRC20', $innocent->id . ':TS9 - ERC20'],
            '--reason' => '會與服務中的列同名',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS9(TRC20)', $this->nameOf($clash));
        $this->assertSame('TS9(ERC20)', $this->nameOf($innocent), '任一配對不合格即整批零寫入');
        $this->assertSame(0, $this->auditCount($innocent));
    }

    #[Group('rules/standard-bank-list#R-30')]
    public function test_namesake_check_ignores_case(): void
    {
        $this->seedBank('ts9 - trc20', 'TS9H');
        $bank = $this->seedBank('TS9(TRC20)', 'TS9I');

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':TS9 - TRC20'],
            '--reason' => '只差大小寫也算同名',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS9(TRC20)', $this->nameOf($bank));
    }

    #[Group('rules/standard-bank-list#R-30')]
    public function test_namesake_in_other_group_or_inactive_row_does_not_block(): void
    {
        $this->seedBank('TS9 - TRC20', 'TS9J', self::OTHER_AGENT_GROUP);
        $this->seedBank('TS9 - TRC20', 'TS9K', self::AGENT_GROUP, status: 0);
        $bank = $this->seedBank('TS9(TRC20)', 'TS9L');

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':TS9 - TRC20'],
            '--reason' => '別的支付組別同名、或同名列已停用都不衝突',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS9 - TRC20', $this->nameOf($bank));
    }

    #[Group('rules/standard-bank-list#R-30')]
    public function test_blocks_when_two_pairs_in_same_scope_get_same_name(): void
    {
        $first = $this->seedBank('TS9(TRC20)', 'TS9M');
        $second = $this->seedBank('TS9(ERC20)', 'TS9N');

        $this->artisan('bank:set-name', [
            '--pair' => [$first->id . ':TS9 - TRC20', $second->id . ':TS9 - trc20'],
            '--reason' => '同一批改成同一個名稱',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS9(TRC20)', $this->nameOf($first));
        $this->assertSame('TS9(ERC20)', $this->nameOf($second));
    }

    #[Group('rules/standard-bank-list#R-30')]
    public function test_blocks_soft_deleted_and_missing_rows(): void
    {
        $deleted = $this->seedBank('TS9(TRC20)', 'TS9O');
        Bank::query()->whereKey($deleted->id)->update(['deleted_at' => now()]);

        $this->artisan('bank:set-name', [
            '--pair' => [$deleted->id . ':TS9 - TRC20'],
            '--reason' => '已標刪的列不改',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS9(TRC20)', $this->nameOf($deleted));

        $this->artisan('bank:set-name', [
            '--pair' => ['999999999:TS9 - TRC20'],
            '--reason' => '不存在的 id',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    #[Group('rules/standard-bank-list#R-32')]
    public function test_name_may_contain_colon_and_spaces(): void
    {
        $bank = $this->seedBank('TS9(TRC20)', 'TS9P');

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':  TS9: TRC20  '],
            '--reason' => '以第一個冒號切開、名稱頭尾空白去掉',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS9: TRC20', $this->nameOf($bank));
    }

    #[Group('rules/standard-bank-list#R-28')]
    #[Group('rules/standard-bank-list#R-32')]
    public function test_rejects_malformed_input(): void
    {
        $bank = $this->seedBank('TS9(TRC20)', 'TS9Q');

        $this->artisan('bank:set-name', ['--pair' => [$bank->id . ':TS9 - TRC20']])
            ->assertExitCode(1);

        $this->artisan('bank:set-name', ['--pair' => ['abc:TS9'], '--reason' => '格式錯'])
            ->assertExitCode(1);

        $this->artisan('bank:set-name', ['--pair' => [$bank->id . ':   '], '--reason' => '名稱空白'])
            ->assertExitCode(1);

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':' . str_repeat('名', 101)],
            '--reason' => '超過 banks.name 欄位長度',
        ])->assertExitCode(1);

        $this->artisan('bank:set-name', [
            '--pair' => [$bank->id . ':TS9 - TRC20', $bank->id . ':TS9 - ERC20'],
            '--reason' => '同一列重複指定',
        ])->assertExitCode(1);

        $this->assertSame('TS9(TRC20)', $this->nameOf($bank));
    }
}
