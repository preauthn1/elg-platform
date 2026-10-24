<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Console\Commands\CashFlow\Bank\RenameBankCommand;
use App\Services\CashFlow\Bank\AgentBankSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * RenameBankCommand 測試（軌1：group-aware 同行不同碼收斂）
 *
 * 與 MergeBaseSynonymBanks 的差別：配對由參數指定（非寫死表）、限定單一 agent_group、
 * 且會同步 cash_flow_banks.bank_code 至 survivor 碼。後者是斷代付風險所在，因此測試
 * 大半在守四種出口：改指、留置（碼在族外／幣別不符／撞唯一鍵）、死槽阻斷、
 * 送單設定不等價阻斷。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/RenameBankCommandTest.php
 */
class RenameBankCommandTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    private const SURVIVOR_ID = 990001;

    private const LOSER_ID = 990002;

    private const LOSER2_ID = 990003;

    /** group 0 的 active 模板列——AgentBankSyncService 就是照它把碼同步進各 agent group。 */
    private const BASE_BANK_ID = 990004;

    /** 驗 sync 復活鏈需要非 0 的目標 group（base 固定為 0，同一個會變成自我參照）。 */
    private const OTHER_GROUP_ID = 70;

    private const LOSER2_CODE = 'TESTLOS2';

    private const SURVIVOR_CODE = 'TESTSUR1';

    private const LOSER_CODE = 'TESTLOS1';

    /** 不屬本次收斂碼族的碼，模擬 cfb 列實際服務的是別 group 的 agent 層碼。 */
    private const FOREIGN_CODE = 'ZZFOREIGN1';

    private const CASH_FLOW_ID = 99901;

    private const AGENT_GROUP_ID = 0;

    private const CURRENCY_ID = 1;

    private const ACF_ID = 990901;

    private const BANK_TYPE = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $bankIds = [self::SURVIVOR_ID, self::LOSER_ID, self::LOSER2_ID, self::BASE_BANK_ID];

        DB::table('cash_flow_banks')->whereIn('bank_id', $bankIds)->delete();
        DB::table('bank_dedup_logs')->whereIn('survivor_bank_id', $bankIds)->delete();
        DB::table('bank_dedup_logs')->whereIn('loser_bank_id', $bankIds)->delete();
        DB::table('banks')->whereIn('id', $bankIds)->delete();
        DB::table('agent_cash_flows')->where('id', self::ACF_ID)->delete();
        DB::table('cash_flows')->where('id', self::CASH_FLOW_ID)->delete();
        DB::table('member_bank_accounts')->whereIn('bank_id', $bankIds)->delete();
        DB::table('withdrawal_orders')->whereIn('bank_id', $bankIds)->delete();
        DB::table('order_images')->whereIn('bank_id', $bankIds)->delete();
        DB::table('realname_validation_requests')->whereIn('bank_id', $bankIds)->delete();
    }

    /**
     * 建立指向指定銀行的周邊資料，用於驗證 re-point 連動。
     *
     * @return array{mba: int, wo: int, oi: int}
     */
    private function seedSatelliteRows(int $bankId, string $bankCode): array
    {
        $mba = (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => 990001,
            'bank_id' => $bankId,
            'account_name' => 'TEST HOLDER',
            'account_no' => '0000000001',
            'operator_cs' => 0,
        ]);

        $wo = (int) DB::table('withdrawal_orders')->insertGetId([
            'member_id' => 990001,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'bank_name' => '(TEST) - Old Name',
            'account_name' => 'TEST HOLDER',
            'account_no' => '0000000001',
            'amount' => '100.0000',
            'change_logs' => '[]',
            'third_party_order_no' => 'TESTWO0001',
        ]);

        $oi = (int) DB::table('order_images')->insertGetId([
            'order_id' => 990001,
            'bank_id' => $bankId,
            'image' => 'test.png',
        ]);

        return ['mba' => $mba, 'wo' => $wo, 'oi' => $oi];
    }

    /**
     * 建一組代付渠道（cash_flows.type=3）與其 available_banks JSON。
     *
     * @param  list<array{BankCode_ELG: string, BankCode_Third: string}>  $availableBanks
     */
    private function seedPayoutChannel(array $availableBanks): void
    {
        DB::table('cash_flows')->insert([
            'id' => self::CASH_FLOW_ID,
            'name' => '(TEST) Rename Payout',
            'type' => 3,
            'status' => 1,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('agent_cash_flows')->insert([
            'id' => self::ACF_ID,
            'agent_group_id' => self::AGENT_GROUP_ID,
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => self::CURRENCY_ID,
            'status' => 1,
            'admin' => 0,
            'info' => json_encode(['available_banks' => $availableBanks], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAvailableBanks(): array
    {
        $info = json_decode(
            (string) DB::table('agent_cash_flows')->where('id', self::ACF_ID)->value('info'),
            true,
        );

        return $info['available_banks'] ?? [];
    }

    private function insertBank(
        int $id,
        string $code,
        int $status,
        ?int $agentGroupId = null,
        ?int $currencyId = null,
        ?int $type = null,
        bool $softDeleted = false,
    ): void {
        DB::table('banks')->insert([
            'id' => $id,
            'agent_group_id' => $agentGroupId ?? self::AGENT_GROUP_ID,
            'name' => '(TEST) - Rename Sample',
            'code' => $code,
            'currency_id' => $currencyId ?? self::CURRENCY_ID,
            'type' => $type ?? self::BANK_TYPE,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $softDeleted ? now() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runRename(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('bank:rename-bank', array_merge([
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
            '--force' => true,
        ], $options));
    }

    private function assertLoserUntouched(int $cfbId): void
    {
        $cfb = DB::table('cash_flow_banks')->where('id', $cfbId)->first();

        $this->assertSame(self::LOSER_ID, (int) $cfb->bank_id, 'preflight 擋下時 cfb 不應被 re-point');
        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            'preflight 擋下時 loser 不應被停用',
        );
    }

    private function insertRealnameRequest(int $bankId): int
    {
        return (int) DB::table('realname_validation_requests')->insertGetId([
            'username' => 'testuser',
            'realname' => 'TEST HOLDER',
            'bank_name' => '(TEST) - Rename Sample',
            'bank_account' => '0000000001',
            'bank_id' => $bankId,
            'status' => 0,
        ]);
    }

    private function insertMemberBinding(int $memberId, int $bankId, string $accountNo): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => $memberId,
            'bank_id' => $bankId,
            'account_name' => 'TEST HOLDER',
            'account_no' => $accountNo,
            'status' => 1,
            'operator_cs' => 0,
        ]);
    }

    private function insertCashFlowBank(
        int $bankId,
        string $bankCode,
        int $cashFlowId,
        ?string $providerBankCode = null,
        int $status = 1,
        ?int $currencyId = null,
    ): int {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => $currencyId ?? self::CURRENCY_ID,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => $providerBankCode ?? $bankCode,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_repoints_cash_flow_bank_and_syncs_bank_code_when_no_collision(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $cfb = DB::table('cash_flow_banks')->where('id', $cfbId)->first();

        $this->assertSame(self::SURVIVOR_ID, (int) $cfb->bank_id, 'cfb 應 re-point 至 survivor');
        $this->assertSame(self::SURVIVOR_CODE, $cfb->bank_code, 'cfb.bank_code 應同步為 survivor 碼');

        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            'loser 銀行應停用',
        );
    }

    public function test_records_audit_trail_for_repoint_and_deactivation(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(0);

        $repoint = DB::table('bank_dedup_logs')
            ->where('action', 'rename_repoint_cfb')
            ->where('loser_bank_id', self::LOSER_ID)
            ->first();

        $this->assertNotNull($repoint, '應記錄 cfb re-point');
        $this->assertSame(self::SURVIVOR_ID, (int) $repoint->survivor_bank_id);
        $this->assertSame(self::AGENT_GROUP_ID, (int) $repoint->agent_group_id);
        $this->assertSame(self::SURVIVOR_CODE, $repoint->code, 'code 欄記錄收斂後的碼');
        $this->assertStringContainsString(
            self::LOSER_CODE,
            (string) $repoint->detail,
            'detail 須保留原碼，否則無從得知改前的值',
        );

        $this->assertNotNull(
            DB::table('bank_dedup_logs')
                ->where('action', 'rename_deactivate_bank')
                ->where('loser_bank_id', self::LOSER_ID)
                ->first(),
            '應記錄 loser 停用',
        );
    }

    /**
     * 這支命令不可逆，審計必須能被程式讀回來重放。detail 若是人話，未來的 rollback
     * 只能靠字串解析，格式一改就碎。改存 JSON 並記下「異動前的值」——還原就是寫回去。
     */
    public function test_audit_detail_is_machine_readable_json_carrying_previous_values(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')->where('action', 'rename_repoint_cfb')->first();
        $detail = json_decode((string) $log->detail, true);

        $this->assertIsArray($detail, 'detail 須為可解析的 JSON');
        $this->assertSame('cash_flow_banks', $detail['t'], '須指出被異動的表');
        $this->assertSame($cfbId, $detail['id'], '須指出被異動的列 PK');
        $this->assertSame(self::LOSER_ID, $detail['was']['bank_id'], '須記錄異動前的 bank_id');
        $this->assertSame(self::LOSER_CODE, $detail['was']['bank_code'], '須記錄異動前的 bank_code');
    }

    /**
     * created_at 只有秒級精度，同一秒對同一 survivor 跑兩次不同變體就分不開。
     * 執行代號讓 rollback 能精確框出「這一次」動了哪些列。
     */
    public function test_all_audit_rows_of_one_run_share_a_run_id(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);
        $this->insertMemberBinding(990001, self::LOSER_ID, '1111111111');

        $this->runRename()->assertExitCode(0);

        $runIds = DB::table('bank_dedup_logs')
            ->where('survivor_bank_id', self::SURVIVOR_ID)
            ->pluck('detail')
            ->map(static fn ($detail): ?string => json_decode((string) $detail, true)['run'] ?? null)
            ->unique()
            ->all();

        $this->assertCount(1, $runIds, '同一次執行的審計列須共用同一個執行代號');
        $this->assertNotNull($runIds[0], '每一列都須帶執行代號');
    }

    /**
     * 成功時必須有輸出。
     *
     * 2026-09-10 VWorld 實跑：這支寫進 378 列，輸出卻停在計畫那兩行——因為成功路徑
     * 原本直接 return SUCCESS、一個字都不印，而每一條失敗路徑都有輸出。操作者當場
     * 分不出「跑完了」與「根本沒跑」，只能事後查 DB 才確認。
     */
    public function test_prints_a_completion_line_on_success(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()
            ->expectsOutputToContain('收斂完成')
            ->assertExitCode(0);
    }

    /** 完成訊息要帶逐 action 的筆數，操作者才有得對帳。 */
    public function test_completion_output_breaks_down_the_audit_rows_by_action(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()
            ->expectsOutputToContain('rename_deactivate_bank 1')
            ->assertExitCode(0);
    }

    /** 沒有 rollback 命令，事後反演唯一的索引就是執行代號，必須印出來。 */
    public function test_completion_output_points_at_the_run_id_for_replay(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()
            ->expectsOutputToContain('detail.run')
            ->assertExitCode(0);
    }

    /** 預演沒有寫入，就不該出現完成訊息——否則等於謊報。 */
    public function test_dry_run_does_not_print_a_completion_line(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename(['--execute' => false])
            ->doesntExpectOutputToContain('收斂完成')
            ->assertExitCode(0);
    }

    public function test_writes_no_audit_trail_in_dry_run(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename(['--execute' => false])->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('loser_bank_id', self::LOSER_ID)->count(),
            'dry-run 不應留下審計紀錄',
        );
    }

    public function test_renames_available_banks_entry_to_survivor_code(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_A'],
            ['BankCode_ELG' => 'OTHERCODE', 'BankCode_Third' => 'THIRD_B'],
        ]);

        $this->runRename()->assertExitCode(0);

        $banks = $this->readAvailableBanks();

        $this->assertSame(self::SURVIVOR_CODE, $banks[0]['BankCode_ELG'], '舊碼應改為 survivor 碼');
        $this->assertSame('THIRD_A', $banks[0]['BankCode_Third'], '三方碼與其餘鍵不得被動到');
        $this->assertSame('OTHERCODE', $banks[1]['BankCode_ELG'], '不相干的 entry 不應被改');
    }

    public function test_skips_available_banks_rename_when_target_code_already_present(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        // 兩碼同時在列：直接 rename 會產生重複 BankCode_ELG，代付查表將取到不確定的一筆。
        // 三方碼相同，收斂後查表拿到的送單參數不變，故留置是安全的。
        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::SURVIVOR_CODE, 'BankCode_Third' => 'THIRD_SAME'],
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_SAME'],
        ]);

        $this->runRename()->assertExitCode(0);

        $banks = $this->readAvailableBanks();
        $elgCodes = array_column($banks, 'BankCode_ELG');

        $this->assertSame(
            count($elgCodes),
            count(array_unique($elgCodes)),
            'available_banks 不得出現重複 BankCode_ELG',
        );
    }

    /**
     * 撞碼時只留置那一筆 entry 攔不住路由搬家。
     *
     * 綁定改指 survivor 後新提款帶的是 survivor 碼，舊路徑
     * （WithdrawalPayoutTrait::convertPayoutBankCodeFromJson）以 BankCode_ELG 命中的
     * 就會變成 survivor 那筆 entry、取到它的 BankCode_Third。兩者不同即等於未經比對
     * 換掉送單參數——與 cash_flow_banks 的等價閘是同一件事，必須同樣零寫入。
     */
    public function test_blocks_when_colliding_available_banks_entries_carry_different_provider_codes(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::SURVIVOR_CODE, 'BankCode_Third' => 'THIRD_KEEP'],
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_DUP'],
        ]);

        $this->runRename()->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '阻斷時不得有任何寫入',
        );

        $this->assertSame(
            [self::SURVIVOR_CODE, self::LOSER_CODE],
            array_column($this->readAvailableBanks(), 'BankCode_ELG'),
            '阻斷時 available_banks 應原封不動',
        );
    }

    /** 審計把 loser_bank_id 填成 survivor id 等於無從追查是哪個變體的碼被改掉。 */
    public function test_available_banks_audit_records_the_actual_variant_bank_id(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->seedPayoutChannel([['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_A']]);

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('bank_dedup_logs')->where('action', 'rename_align_ab')->value('loser_bank_id'),
            'JSON 改寫的審計須指出實際來源變體',
        );
    }

    /** 重跑不得把原始收斂時間刷成當下，否則 updated_at 回推鍵與審計時序都失真。 */
    public function test_does_not_refresh_deleted_at_of_an_already_converged_variant(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 0);
        DB::table('banks')->where('id', self::LOSER_ID)->update(['deleted_at' => '2020-01-01 00:00:00']);

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) DB::table('banks')->where('id', self::LOSER_ID)->value('deleted_at'),
            '重跑不得刷掉原始收斂時間',
        );
    }

    /**
     * 停用審計原本寫死 was = status 1／deleted_at null，但 preflight 只要求 survivor
     * 為 active 未標刪，對變體沒有對應限制（重跑已停用／已標刪的變體是既有用例）。
     * 謊報前值會讓事後追溯與任何反演都被誤導。
     */
    public function test_deactivate_audit_records_actual_before_state_of_an_already_deleted_variant(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 0);
        DB::table('banks')->where('id', self::LOSER_ID)->update(['deleted_at' => '2020-01-01 00:00:00']);

        $this->runRename()->assertExitCode(0);

        $detail = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'rename_deactivate_bank')
            ->where('loser_bank_id', self::LOSER_ID)
            ->value('detail'), true);

        $this->assertSame(0, $detail['was']['status'] ?? null, '變體原本已停用，was 不得謊報為啟用中');
        $this->assertSame(
            '2020-01-01 00:00:00',
            $detail['was']['deleted_at'] ?? null,
            '變體原本已標刪，was 不得謊報為未標刪',
        );
        $this->assertSame(
            '2020-01-01 00:00:00',
            $detail['aft']['deleted_at'] ?? null,
            'aft 須是實際寫入後的值：deleted_at 非空時本命令保留原值',
        );
    }

    /** 變體原為啟用中時，was／aft 須各自反映實際的前後值。 */
    public function test_deactivate_audit_records_before_and_after_state_of_an_active_variant(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->runRename()->assertExitCode(0);

        $detail = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'rename_deactivate_bank')
            ->where('loser_bank_id', self::LOSER_ID)
            ->value('detail'), true);

        $this->assertSame(1, $detail['was']['status'] ?? null);
        $this->assertArrayHasKey('deleted_at', $detail['was'], 'was 須明確記下 deleted_at');
        $this->assertNull($detail['was']['deleted_at'], '原本未標刪，was 須為 null');
        $this->assertSame(0, $detail['aft']['status'] ?? null);
        $this->assertSame(
            (string) DB::table('banks')->where('id', self::LOSER_ID)->value('deleted_at'),
            $detail['aft']['deleted_at'] ?? null,
            'aft.deleted_at 須等於實際落庫的值',
        );
    }

    /** 出款單改指會同步 code／name 三欄，manifest 必須完整記下前後值才可能被驗證或反演。 */
    public function test_withdrawal_order_audit_records_all_three_synced_columns(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $ids = $this->seedSatelliteRows(self::LOSER_ID, self::LOSER_CODE);

        $this->runRename()->assertExitCode(0);

        $detail = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'rename_repoint_satellite')
            ->where('detail', 'like', '%withdrawal_orders%')
            ->value('detail'), true);

        $order = DB::table('withdrawal_orders')->where('id', $ids['wo'])->first();

        $this->assertTrue($detail['rowwise'] ?? null);
        $this->assertSame($ids['wo'], $detail['id'] ?? null);
        $this->assertSame(self::LOSER_ID, $detail['was']['bank_id'] ?? null);
        $this->assertSame(self::LOSER_CODE, $detail['was']['bank_code'] ?? null);
        $this->assertSame(self::SURVIVOR_ID, $detail['aft']['bank_id'] ?? null);
        $this->assertSame((string) $order->bank_code, $detail['aft']['bank_code'] ?? null);
        $this->assertSame((string) $order->bank_name, $detail['aft']['bank_name'] ?? null);
    }

    /**
     * 逐列審計是 rollback 唯一的 manifest 來源，但實測單一銀行最多有 39 萬列出款單。
     * 超量時必須在動手前中止（估算階段純 count 不加鎖），而不是鎖完數十萬列才反悔。
     */
    public function test_aborts_before_writing_when_rowwise_audit_would_exceed_the_limit(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $limit = (new ReflectionClass(RenameBankCommand::class))
            ->getConstant('ROWWISE_AUDIT_LIMIT');

        $rows = [];

        for ($i = 0; $i <= $limit; $i++) {
            $rows[] = [
                'username' => 'testuser',
                'realname' => 'TEST HOLDER',
                'bank_name' => '(TEST) - Rename Sample',
                'bank_account' => '0000000001',
                'bank_id' => self::LOSER_ID,
                'status' => 0,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('realname_validation_requests')->insert($chunk);
        }

        $this->runRename()->assertFailed();

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '超量中止後不得留下任何寫入，變體必須維持啟用',
        );

        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('loser_bank_id', self::LOSER_ID)->count(),
            '超量中止後不得留下審計列',
        );

        // --force 只跳過互動確認，不得順帶取得「明知不可回滾仍執行」的授權。
        $this->runRename(['--force' => true])->assertFailed();
    }

    /**
     * 高流量銀行的收斂是既有實務（AW77 BCA 14,407 綁卡、TPower TnG 37,006 綁卡都執行過），
     * 六站現況也有單一銀行 2.8 萬綁卡／40 萬出款單的量級。硬上限若沒有明示放行的出口，
     * 等於讓這些站再也無法收斂——故超量時允許帶旗標改以彙總記帳，並留下不可回滾標記。
     */
    public function test_allows_exceeding_the_limit_with_explicit_opt_in_and_marks_the_run_unrollbackable(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $limit = (new ReflectionClass(RenameBankCommand::class))->getConstant('ROWWISE_AUDIT_LIMIT');

        $rows = [];

        for ($i = 0; $i <= $limit; $i++) {
            $rows[] = [
                'username' => 'testuser',
                'realname' => 'TEST HOLDER',
                'bank_name' => '(TEST) - Rename Sample',
                'bank_account' => '0000000001',
                'bank_id' => self::LOSER_ID,
                'status' => 0,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('realname_validation_requests')->insert($chunk);
        }

        $this->runRename(['--allow-unrollbackable' => true])->assertExitCode(0);

        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '明示放行後收斂應照常完成',
        );

        $this->assertSame(
            1,
            DB::table('bank_dedup_logs')->where('action', 'rename_run_unrollbackable')->count(),
            '不可回滾的執行必須留下 run 級標記',
        );

        $summary = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'rename_repoint_satellite')
            ->value('detail'), true);

        $this->assertFalse($summary['rowwise'] ?? null, '彙總模式的審計須標明不可逐列還原');
        $this->assertSame($limit + 1, $summary['n'] ?? null, '彙總審計須記下實際搬移筆數');
    }

    /**
     * 彙總模式下綁定停用仍逐列記帳（客訴要查得到是哪一筆被藏起來），因此不得再受
     * 逐列上限攔阻——否則高流量白標帶了旗標仍舊跑不動，等於沒有出口。
     */
    public function test_summary_mode_does_not_let_the_rowwise_limit_block_binding_audits(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $limit = (new ReflectionClass(RenameBankCommand::class))->getConstant('ROWWISE_AUDIT_LIMIT');

        // 每位會員都同時綁 survivor 與變體：變體側全數落入「停用重複綁定」逐列審計。
        $rows = [];

        for ($i = 0; $i <= $limit; $i++) {
            $memberId = 900000 + $i;

            foreach ([self::SURVIVOR_ID, self::LOSER_ID] as $bankId) {
                $rows[] = [
                    'member_id' => $memberId,
                    'bank_id' => $bankId,
                    'account_name' => 'TEST HOLDER',
                    'account_no' => '0000000001',
                    'operator_cs' => 0,
                    'status' => 1,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('member_bank_accounts')->insert($chunk);
        }

        $this->runRename(['--allow-unrollbackable' => true])->assertExitCode(0);

        $this->assertSame(
            $limit + 1,
            DB::table('bank_dedup_logs')->where('action', 'rename_deactivate_binding')->count(),
            '綁定停用須逐列留痕，且不因超過逐列上限而中止',
        );

        $this->assertSame(
            0,
            DB::table('member_bank_accounts')
                ->where('bank_id', self::LOSER_ID)
                ->where('status', 1)
                ->count(),
            '變體側的重複綁定應全數停用',
        );
    }

    /**
     * 收斂尾端的殘留檢查只認活躍引用：停用的 cfb 留在停用的變體身上不影響代付查表
     * （查表要求 status=1），buildPlan 對「停用變體列撞鍵」也是直接跳過、不歸入任何
     * 類別——若殘留檢查連它一起數，這個既有的合法情境會被誤判成競態而整批回滾。
     */
    public function test_residual_check_ignores_an_inactive_cash_flow_bank_left_on_the_variant(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 1);

        // 啟用列會搶下 survivor 的唯一鍵位，停用列因此真的留在變體身上——
        // 這正是 buildPlan 直接跳過、不歸入任何類別的情境。
        $inactiveCfb = $this->insertCashFlowBank(
            self::LOSER_ID,
            self::LOSER_CODE,
            self::CASH_FLOW_ID,
            providerBankCode: 'PROV_SAME',
            status: 0,
        );
        $this->insertCashFlowBank(
            self::LOSER2_ID,
            self::LOSER2_CODE,
            self::CASH_FLOW_ID,
            providerBankCode: 'PROV_SAME',
            status: 1,
        );

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertExitCode(0);

        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('cash_flow_banks')->where('id', $inactiveCfb)->value('bank_id'),
            '停用列留在變體身上是既有的合法情境',
        );

        $this->assertSame(
            0,
            (int) DB::table('cash_flow_banks')->where('id', $inactiveCfb)->value('status'),
            '留置的停用列不得被改動',
        );
    }

    /**
     * available_banks 內的變體碼與其他引用面同構：舊路徑代付查表以 BankCode_ELG 命中，
     * 殘留的變體碼會把送單導向已停用的銀行列。留置項（survivor 碼已存在而 skip 的）
     * 必須排除在外，否則這個既有的合法情境會被誤判成競態。
     */
    public function test_retained_available_banks_entry_does_not_trigger_the_residual_check(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        // 兩碼並存且三方碼相同：改寫會製造重複 BankCode_ELG，故變體 entry 原樣留置。
        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::SURVIVOR_CODE, 'BankCode_Third' => 'THIRD_SAME'],
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_SAME'],
        ]);

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'rename_align_ab')->count(),
            '兩碼並存時不得改寫，只留 skip 痕跡',
        );

        $this->assertSame(
            1,
            DB::table('bank_dedup_logs')->where('action', 'rename_ab_skipped')->count(),
            '留置的 entry 須留下 skip 審計',
        );
    }

    /**
     * 同一 ACF 內有兩個變體碼、且 survivor 碼原本不存在時：第一個變體改寫後 survivor 碼
     * 才出現，第二個變體便因「survivor 已存在」被 skip——但那個 skip 從未經過三方碼
     * 等價比對（findAvailableBanksConflicts 只在 survivor 碼原就並存時才檢查）。
     *
     * 舊路徑 payout lookup 以 BankCode_ELG 命中，會拿到第一個 entry 的三方碼，等於把
     * 第二個變體的流量靜默改道。此情境屬既有缺陷，殘留檢查不得放行，須整批中止。
     */
    public function test_aborts_when_a_second_variant_entry_is_skipped_without_third_party_code_check(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 1);

        // survivor 碼不在其中：兩個變體碼並存、三方碼不同。
        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'THIRD_A'],
            ['BankCode_ELG' => self::LOSER2_CODE, 'BankCode_Third' => 'THIRD_B'],
        ]);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertFailed();

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '中止後不得留下任何寫入',
        );

        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'rename_align_ab')->count(),
            '中止後審計亦須回滾',
        );
    }

    /**
     * 判讀與更新之間若有新列插入指向變體，它不會被改指（只更新已取到的 id），
     * 而變體此時已停用——那些引用就成了孤兒。必須整批回滾而非回報成功。
     */
    public function test_aborts_when_new_references_appear_after_the_ids_were_collected(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        // 模擬「改指完成後才出現的引用」：以 realname 表的既有列在改指後重新指回變體，
        // 等價於判讀期間插入的新列——收斂尾端的殘留檢查必須抓到它。
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'update `realname_validation_requests`')) {
                DB::table('realname_validation_requests')->insert([
                    'username' => 'racer',
                    'realname' => 'RACE HOLDER',
                    'bank_name' => '(TEST) - Rename Sample',
                    'bank_account' => '0000000002',
                    'bank_id' => self::LOSER_ID,
                    'status' => 0,
                ]);
            }
        });

        $this->insertRealnameRequest(self::LOSER_ID);

        $this->runRename()->assertFailed();

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '殘留引用導致中止後，變體不得留在停用狀態',
        );
    }

    public function test_repoints_satellite_tables_to_survivor(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $ids = $this->seedSatelliteRows(self::LOSER_ID, self::LOSER_CODE);

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            self::SURVIVOR_ID,
            (int) DB::table('member_bank_accounts')->where('id', $ids['mba'])->value('bank_id'),
            '會員綁定應改指 survivor，否則綁定成孤兒',
        );

        // order_images.bank_id 存的是 bank_accounts.id（migration 註解「銀行帳戶id」、
        // AutoDepositController:181 寫入會員選的入款帳戶），與 banks.id 是兩套 id 空間。
        // TPower 實測 328 個相異值 100% 落在 bank_accounts、上限 378。誤改不可逆。
        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('order_images')->where('id', $ids['oi'])->value('bank_id'),
            'order_images 的 bank_id 是 bank_accounts.id，不得被當成 banks.id 改指',
        );

        // 歷史提款單同步 code/name，沿用 dedup 家族既有慣例
        $order = DB::table('withdrawal_orders')->where('id', $ids['wo'])->first();
        $this->assertSame(self::SURVIVOR_ID, (int) $order->bank_id);
        $this->assertSame(self::SURVIVOR_CODE, $order->bank_code);
        $this->assertSame('(TEST) - Rename Sample', $order->bank_name);
    }

    /**
     * member_bank_accounts 沒有 (member_id, bank_id) 唯一鍵，整批 re-point 不會報錯、
     * 而是靜默製造重複：同時綁 survivor 與變體的會員，收斂後在同一家銀行下會出現
     * 兩筆 active 綁定，前台下拉重複、提款帶哪個帳號變成不確定。
     *
     * 沿用 BankActiveDedupService:258-296 的兩案分流：已綁 survivor 者停用其變體綁定，
     * 只綁變體者才 re-point。
     */
    public function test_deactivates_duplicate_member_binding_instead_of_creating_a_second_one(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertMemberBinding(990001, self::SURVIVOR_ID, '1111111111');
        $duplicateBinding = $this->insertMemberBinding(990001, self::LOSER_ID, '2222222222');
        $soleBinding = $this->insertMemberBinding(990002, self::LOSER_ID, '3333333333');

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            0,
            (int) DB::table('member_bank_accounts')->where('id', $duplicateBinding)->value('status'),
            '已綁 survivor 的會員，其變體綁定應停用而非 re-point',
        );

        $this->assertSame(
            1,
            DB::table('member_bank_accounts')
                ->where('member_id', 990001)
                ->where('bank_id', self::SURVIVOR_ID)
                ->where('status', 1)
                ->count(),
            '同一會員在 survivor 下不得出現兩筆 active 綁定',
        );

        $sole = DB::table('member_bank_accounts')->where('id', $soleBinding)->first();

        $this->assertSame(self::SURVIVOR_ID, (int) $sole->bank_id, '只綁變體的會員應照常 re-point');
        $this->assertSame(1, (int) $sole->status, 're-point 不得順手改動綁定狀態');

        $log = DB::table('bank_dedup_logs')
            ->where('action', 'rename_deactivate_binding')
            ->where('loser_bank_id', self::LOSER_ID)
            ->first();

        $this->assertNotNull($log, '停用綁定須逐列留審計，否則爭議時無從回推是哪個會員哪筆綁定');

        // bank_dedup_logs 有 member_id／member_bank_account_id 專用欄位，DedupRollback:106,112
        // 就是靠它們逐列還原的；只寫進 detail 字串則工具讀不到。
        $this->assertSame(990001, (int) $log->member_id, '審計須填 member_id 欄位而非只寫在 detail');
        $this->assertSame(
            $duplicateBinding,
            (int) $log->member_bank_account_id,
            '審計須填 member_bank_account_id 欄位，否則無法逐列還原',
        );
    }

    /**
     * 停用的 survivor 綁定不算「已綁 survivor」。
     *
     * 算進去的話兩案分流會同時把該會員推開：deactivateDuplicateBindings 認為他已綁
     * survivor 而停掉唯一有效的變體綁定，repointSoleBindings 又因同一理由排除他，
     * 結果是會員零個有效綁定、無法提款。
     */
    public function test_member_whose_survivor_binding_is_inactive_keeps_an_active_binding(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $inactiveSurvivorBinding = $this->insertMemberBinding(990001, self::SURVIVOR_ID, '1111111111');
        DB::table('member_bank_accounts')->where('id', $inactiveSurvivorBinding)->update(['status' => 0]);
        $activeLoserBinding = $this->insertMemberBinding(990001, self::LOSER_ID, '2222222222');

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('member_bank_accounts')->where('member_id', 990001)->where('status', 1)->count(),
            '收斂後會員必須仍有有效綁定，否則提款直接斷掉',
        );

        $binding = DB::table('member_bank_accounts')->where('id', $activeLoserBinding)->first();

        $this->assertSame(self::SURVIVOR_ID, (int) $binding->bank_id, '唯一的有效綁定應改指 survivor');
        $this->assertSame(1, (int) $binding->status, '唯一的有效綁定不得被停用');
    }

    /**
     * 周邊表審計原本只寫一列總數、且 loser_bank_id 一律填 $loserIds[0]——
     * 多變體收斂時第二個變體的異動會全記在第一個變體名下，事後追查直接失準。
     *
     * 改為逐列審計（每列一筆、各掛自己的 loser_bank_id）：彙總列只有筆數，
     * 無法逐列驗證也無法反演；量級風險改由 ROWWISE_AUDIT_LIMIT 把關。
     */
    public function test_records_satellite_audit_per_variant_row_by_row(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 1);

        $firstRequest = $this->insertRealnameRequest(self::LOSER_ID);
        $this->insertRealnameRequest(self::LOSER2_ID);
        $this->insertRealnameRequest(self::LOSER2_ID);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertExitCode(0);

        $detailsFor = fn (int $loserId): array => DB::table('bank_dedup_logs')
            ->where('action', 'rename_repoint_satellite')
            ->where('loser_bank_id', $loserId)
            ->pluck('detail')
            ->map(fn (string $detail): array => json_decode($detail, true) ?? [])
            ->all();

        $first = $detailsFor(self::LOSER_ID);
        $second = $detailsFor(self::LOSER2_ID);

        $this->assertCount(1, $first, '第一個變體須記自己的列數');
        $this->assertCount(2, $second, '第二個變體的異動不得被記到第一個變體名下');
        $this->assertSame('realname_validation_requests', $first[0]['t'] ?? null);
        $this->assertTrue($first[0]['rowwise'] ?? null, '逐列審計須標明可逐列還原');
        $this->assertSame($firstRequest, $first[0]['id'] ?? null, '須記下被異動的列 PK');
        $this->assertSame(self::LOSER_ID, $first[0]['was']['bank_id'] ?? null, 'was 須為異動前的 bank_id');
        $this->assertSame(self::SURVIVOR_ID, $first[0]['aft']['bank_id'] ?? null, 'aft 須為實際寫入的 bank_id');

        $this->assertNotNull(
            DB::table('realname_validation_requests')->where('bank_id', self::SURVIVOR_ID)->value('updated_at'),
            '周邊表須蓋 updated_at',
        );
    }

    /**
     * 同一會員綁了兩個變體、且沒綁 survivor 時，第一個變體 re-point 後該會員即已有
     * survivor 綁定——第二個變體必須落入「停用」而非再 re-point 一次。
     * 這正是範本逐個 loser 迴圈（而非單次 whereIn 全部 loser）的原因。
     */
    public function test_second_variant_binding_is_deactivated_after_first_one_is_repointed(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 1);

        $this->insertMemberBinding(990003, self::LOSER_ID, '4444444444');
        $this->insertMemberBinding(990003, self::LOSER2_ID, '5555555555');

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('member_bank_accounts')
                ->where('member_id', 990003)
                ->where('bank_id', self::SURVIVOR_ID)
                ->where('status', 1)
                ->count(),
            '綁兩個變體的會員，收斂後在 survivor 下只能剩一筆 active 綁定',
        );
    }

    public function test_leaves_satellite_tables_untouched_in_dry_run(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $ids = $this->seedSatelliteRows(self::LOSER_ID, self::LOSER_CODE);

        $this->runRename(['--execute' => false])->assertExitCode(0);

        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('member_bank_accounts')->where('id', $ids['mba'])->value('bank_id'),
            'dry-run 不得動到會員綁定',
        );
    }

    public function test_writes_nothing_without_execute_flag(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
        ])->assertExitCode(0);

        $cfb = DB::table('cash_flow_banks')->where('id', $cfbId)->first();

        $this->assertSame(self::LOSER_ID, (int) $cfb->bank_id, 'dry-run 不應 re-point');
        $this->assertSame(self::LOSER_CODE, $cfb->bank_code, 'dry-run 不應改寫 bank_code');

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            'dry-run 不應停用 loser',
        );
    }

    public function test_collapses_three_or_more_variants_into_single_survivor(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 0);

        $cfbA = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);
        $cfbB = $this->insertCashFlowBank(self::LOSER2_ID, self::LOSER2_CODE, self::CASH_FLOW_ID + 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID . ',' . self::LOSER2_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        foreach ([$cfbA, $cfbB] as $cfbId) {
            $cfb = DB::table('cash_flow_banks')->where('id', $cfbId)->first();
            $this->assertSame(self::SURVIVOR_ID, (int) $cfb->bank_id, '每個變體的 cfb 都應收斂到 survivor');
            $this->assertSame(self::SURVIVOR_CODE, $cfb->bank_code, '每個變體的 bank_code 都應同步');
        }

        $this->assertSame(
            0,
            (int) DB::table('banks')
                ->whereIn('id', [self::LOSER_ID, self::LOSER2_ID])
                ->where('status', 1)
                ->count(),
            '所有 loser 變體都應停用',
        );
    }

    public function test_rejects_when_variant_belongs_to_another_agent_group(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        // 跨白標誤改正是 ReplaceBank 的地雷之一：碼空間逐 group 獨立，不可跨 group 收斂
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1, agentGroupId: 70);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    public function test_rejects_when_currency_differs(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1, currencyId: self::CURRENCY_ID + 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    public function test_rejects_when_bank_type_differs(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1, type: self::BANK_TYPE + 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    public function test_rejects_when_survivor_is_inactive(): void
    {
        // survivor 停用卻把流量收斂過去，等同讓該行從前台消失
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 0);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    public function test_rejects_when_survivor_listed_as_its_own_variant(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::SURVIVOR_ID])->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    public function test_rejects_when_bank_does_not_exist(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename(['--from' => self::LOSER_ID . ',999999'])->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /** dry-run 只列 re-point 會讓人誤以為撞鍵列已收斂，故留置清單必須一併揭露。 */
    public function test_dry_run_discloses_retained_collision_rows(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        // 兩列 provider 設定相同：撞鍵留置本身合法，路由等價與否另有測試涵蓋
        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');
        $loserCfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');

        $this->runRename(['--execute' => false])
            ->expectsOutputToContain('留置不動')
            ->assertExitCode(0);

        $loserCfb = DB::table('cash_flow_banks')->where('id', $loserCfbId)->first();

        $this->assertSame(1, (int) $loserCfb->status, 'dry-run 不得變更撞鍵列');
        $this->assertSame(self::LOSER_ID, (int) $loserCfb->bank_id, 'dry-run 不得 re-point');
    }

    /**
     * 撞鍵處置對齊 MergeBaseSynonymBanks：活躍渠道留置不動、報表列出轉人工，
     * 其餘可收斂的列照常處理，不因單一撞鍵中止整支命令。
     *
     * 留置後該 cfb 指向已停用的 banks 列，但代付查表以 (cash_flow_id, currency_id,
     * bank_code) 為鍵、不看 bank_id，故該渠道仍正常服務。
     */
    public function test_leaves_active_collision_row_in_place_and_converges_the_rest(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        // 渠道 A：survivor 已佔用該唯一鍵 → loser 同渠道的列撞鍵（兩列 provider 設定相同）
        $survivorCfb = $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');
        $collidingCfb = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');
        // 渠道 B：survivor 無列 → 可正常收斂
        $repointableCfb = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID + 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $collided = DB::table('cash_flow_banks')->where('id', $collidingCfb)->first();

        $this->assertSame(self::LOSER_ID, (int) $collided->bank_id, '撞鍵列不得被 re-point');
        $this->assertSame(self::LOSER_CODE, $collided->bank_code, '撞鍵列的 bank_code 不得被改寫');
        $this->assertSame(1, (int) $collided->status, '撞鍵列不得被停用——活躍渠道一律留置');

        $this->assertSame(
            self::SURVIVOR_CODE,
            DB::table('cash_flow_banks')->where('id', $survivorCfb)->value('bank_code'),
            'survivor 既有列不得被改寫',
        );

        $repointed = DB::table('cash_flow_banks')->where('id', $repointableCfb)->first();

        $this->assertSame(self::SURVIVOR_ID, (int) $repointed->bank_id, '非撞鍵列應照常 re-point');
        $this->assertSame(self::SURVIVOR_CODE, $repointed->bank_code, '非撞鍵列的碼應同步為 survivor 碼');

        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '有撞鍵留置不影響 loser 銀行停用',
        );
    }

    /**
     * 撞鍵「留置」只保住了那一列本身，卻沒攔住路由搬家：命令照樣把會員綁定、提款單、
     * available_banks 全切到 survivor，runtime 之後就改用 survivor 那列的
     * provider_bank_code（WithdrawalPayoutTrait:256 查表回傳的正是該欄）。
     * 兩列的三方碼／限額若不同，等於未經比對就把送單參數換掉——必須 fail-closed。
     */
    public function test_blocks_when_retained_collision_row_routes_to_a_different_provider_code(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_S');
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_L');

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /** 限額不同同樣會改變送單條件，與三方碼一視同仁。 */
    public function test_blocks_when_retained_collision_row_has_different_amount_limits(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $survivorCfb = $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME');

        DB::table('cash_flow_banks')->where('id', $survivorCfb)->update(['max_amount' => '5000.0000']);
        DB::table('cash_flow_banks')->where('id', $cfbId)->update(['max_amount' => '9000.0000']);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /**
     * survivor 在該渠道的列已停用時，唯一鍵仍被佔著（唯一鍵不含 status），
     * 但代付查表要求 status=1。若照舊把 active 變體列判為撞鍵留置、然後把綁定與
     * available_banks 切到 survivor 碼，查表就會落空——斷付而非降級。
     */
    public function test_blocks_when_survivor_holds_the_key_with_an_inactive_row(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 0);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 1);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /**
     * 多變體時「誰先佔到 survivor 的唯一鍵位」不得取決於 --from 的順序：
     * 停用列排在前面若照序處理就會搶位，讓 survivor 只拿到一筆停用列、啟用列反被留置。
     */
    public function test_active_variant_row_wins_the_survivor_key_regardless_of_from_order(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER2_CODE, 1);

        $inactiveCfb = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 0);
        $activeCfb = $this->insertCashFlowBank(self::LOSER2_ID, self::LOSER2_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 1);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertExitCode(0);

        $this->assertSame(
            self::SURVIVOR_ID,
            (int) DB::table('cash_flow_banks')->where('id', $activeCfb)->value('bank_id'),
            '啟用列才該取得 survivor 的唯一鍵位',
        );

        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('cash_flow_banks')->where('id', $inactiveCfb)->value('bank_id'),
            '停用列不得因為排在參數前面就搶走鍵位',
        );
    }

    /**
     * cash_flow_banks.currency_id 與 banks.currency_id 是各自獨立的欄位，drift 時會不一致。
     * 碼族閘只看碼字串，擋不掉「碼對但幣別不對」——把 survivor 的碼寫進別的幣別渠道，
     * 等同讓該渠道指向一個該幣別根本不存在的銀行。
     */
    public function test_retains_cash_flow_bank_row_whose_currency_differs_from_survivor(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $otherCurrencyCfb = $this->insertCashFlowBank(
            self::LOSER_ID,
            self::LOSER_CODE,
            self::CASH_FLOW_ID,
            currencyId: self::CURRENCY_ID + 1,
        );

        $this->runRename()->assertExitCode(0);

        $cfb = DB::table('cash_flow_banks')->where('id', $otherCurrencyCfb)->first();

        $this->assertSame(self::LOSER_ID, (int) $cfb->bank_id, '幣別不符的列不得被 re-point');
        $this->assertSame(self::LOSER_CODE, $cfb->bank_code, '幣別不符的列不得被改寫 bank_code');
    }

    /**
     * cash_flow_banks 沒有 agent_group_id 欄位，「這列服務誰」是由 bank_code 落在誰的碼空間
     * 決定的（代付查表 WithdrawalPayoutTrait 與前台可見鏈 BankVisibilityChecker 皆以
     * bank_code 為鍵、完全不看 bank_id）。故 bank_id 指向本 group 的變體，不代表該列的
     * bank_code 也屬本 group——drift 實證形態正是「bank_id 指 base 列、bank_code 是白標
     * agent 層碼」（cfb 438 SGD0002／cfb 587 WTMY91）。這種列若被同步成 survivor 碼，
     * 等於把別 group 正在服務的碼改掉、直接斷該站代付。
     */
    public function test_retains_cash_flow_bank_row_whose_code_is_outside_the_rename_family(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $foreignCfbId = $this->insertCashFlowBank(self::LOSER_ID, self::FOREIGN_CODE, self::CASH_FLOW_ID);
        $ownCfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID + 1);

        $this->runRename()->assertExitCode(0);

        $foreign = DB::table('cash_flow_banks')->where('id', $foreignCfbId)->first();

        $this->assertSame(self::LOSER_ID, (int) $foreign->bank_id, '碼不屬本次收斂碼族的列不得被 re-point');
        $this->assertSame(self::FOREIGN_CODE, $foreign->bank_code, '碼不屬本次收斂碼族的列不得被改寫');
        $this->assertSame(1, (int) $foreign->status, '留置列不得被停用');

        $own = DB::table('cash_flow_banks')->where('id', $ownCfbId)->first();

        $this->assertSame(self::SURVIVOR_ID, (int) $own->bank_id, '碼族內的列應照常 re-point');
        $this->assertSame(self::SURVIVOR_CODE, $own->bank_code, '碼族內的列應同步為 survivor 碼');

        $this->assertNotNull(
            DB::table('bank_dedup_logs')
                ->where('action', 'rename_retain_foreign_code')
                ->where('loser_bank_id', self::LOSER_ID)
                ->first(),
            '留置須留審計紀錄，否則人工接手無從得知哪些列被跳過',
        );
    }

    /**
     * 只把 loser 設成 status=0 不足以讓收斂定案。
     *
     * AgentBankSyncService::syncToAgentGroup() 以 (agent_group_id, type, currency_id, code)
     * 挑 target，過濾條件只有 whereNull('deleted_at')——**沒有 status 過濾**，
     * `orderByDesc('status')` 在只剩停用列時照樣選中它，接著
     * `$target->update(['status' => $baseBank->status])` 直接把它改回 1（TPower AGRO
     * 776/1300 實案）。標 deleted_at 才會被那道 whereNull 排除。
     *
     * Bank model 無 SoftDeletes，deleted_at 純為清理標記：既有的碼占用查詢
     * （SyncCashFlowsCommand 的 $usedCodes）不帶該過濾，故舊碼不會因此被回收重用。
     *
     * 註：上游 base（group 0）若仍有同碼 active 列，sync 會另建一筆新列——那是
     * base 層的收斂範疇，不在單一 group 的軌1 職責內；本測試只驗被收斂的那一列不復活。
     */
    public function test_marks_loser_deleted_at_so_agent_bank_sync_cannot_revive_it(): void
    {
        $this->insertBank(self::BASE_BANK_ID, self::LOSER_CODE, 1, agentGroupId: 0);
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1, agentGroupId: self::OTHER_GROUP_ID);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1, agentGroupId: self::OTHER_GROUP_ID);

        $this->runRename(['--agent-group' => self::OTHER_GROUP_ID])->assertExitCode(0);

        app(AgentBankSyncService::class)->syncToAgentGroup(
            [self::LOSER_CODE],
            self::OTHER_GROUP_ID,
            self::CURRENCY_ID,
            100,
            10000,
        );

        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '被收斂的 loser 不得被 sync 改回 status=1',
        );

        $this->assertNotNull(
            DB::table('banks')->where('id', self::LOSER_ID)->value('deleted_at'),
            'loser 須標 deleted_at——那是把它排除在 sync target 之外的唯一機制',
        );
    }

    /** 留置清單只印在報表上，dry-run 沒揭露就等於沒發生。 */
    public function test_dry_run_discloses_retained_foreign_code_rows(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::FOREIGN_CODE, self::CASH_FLOW_ID);

        $this->runRename(['--execute' => false])
            ->expectsOutputToContain('不在本次收斂碼族內')
            ->assertExitCode(0);
    }

    /**
     * --agent-group 未帶時，(int) null === 0 會靜默落在 group 0——
     * 而 group 0 是平台級 base 標準庫、是各白標同步的模板來源，
     * 誤動的波及面遠大於任何單一 agent group，故必須 fail-closed 而非取預設。
     */
    public function test_rejects_when_agent_group_option_is_missing(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->artisan('bank:rename-bank', [
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '未指定 agent group 時不得寫入任何資料',
        );
    }

    /** 非數字 agent group 同樣會被 (int) 轉成 0，須與缺參數一併擋下。 */
    public function test_rejects_when_agent_group_option_is_not_numeric(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => 'not-a-number',
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            'agent group 非數字時不得寫入任何資料',
        );
    }

    /**
     * (int) 'abc' 會靜默變成 0，再被 array_filter 當 falsy 丟掉——結果是少收斂一個變體
     * 卻回報成功。打錯字必須擋下而非默默忽略。
     */
    public function test_rejects_non_numeric_from_token(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->runRename(['--from' => self::LOSER_ID . ',abc'])->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '--from 含非數字時不得寫入任何資料',
        );
    }

    /**
     * 兩個變體同碼時，available_banks 改寫靠碼反查來源會固定掛在第一個 id 上，審計失準；
     * 且同碼重複本來就是 bank:dedup-active 的範疇。
     */
    public function test_rejects_when_variants_share_the_same_code(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER_CODE, 1);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER2_ID])->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '變體同碼時不得寫入任何資料',
        );
    }

    /**
     * banks 唯一鍵含 type，但 cash_flow_banks 沒有 type 欄位。同一個碼在本 group 同幣別下
     * 還有別的 type 時，就無從判定某個 cfb 列代表哪個 type，碼族閘會誤判。
     */
    public function test_rejects_when_the_code_is_ambiguous_across_bank_types(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        // 同碼、同幣別、不同 type
        $this->insertBank(self::LOSER2_ID, self::LOSER_CODE, 1, type: self::BANK_TYPE + 1);

        $this->runRename()->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '碼跨 type 歧義時不得寫入任何資料',
        );
    }

    /**
     * 已標刪列不構成跨 type 歧義：dedup／rename 清理終態不再服務任何 cfb。
     * 停用未標刪（status=0、deleted_at=null）者仍要擋——防後台人工翻活場景。
     */
    public function test_allows_when_cross_type_same_code_row_is_soft_deleted(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        // 同碼、同幣別、不同 type，但已標刪 → 不再構成歧義
        $this->insertBank(self::LOSER2_ID, self::LOSER_CODE, 0, type: self::BANK_TYPE + 1, softDeleted: true);

        $this->runRename()->assertExitCode(0);

        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '標刪列不構成歧義時應照常收斂',
        );
    }

    /** 停用但未標刪的跨 type 同碼列仍構成歧義（可被人工翻活，fail-closed）。 */
    public function test_still_rejects_when_cross_type_same_code_row_is_inactive_but_not_deleted(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER_CODE, 0, type: self::BANK_TYPE + 1);

        $this->runRename()->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '停用未標刪列仍構成歧義，不得寫入任何資料',
        );
    }

    /** 標刪列身上仍掛 active cfb 時，cfb 歸屬歧義實質仍在，照擋。 */
    public function test_still_rejects_when_soft_deleted_cross_type_row_has_active_cfb(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertBank(self::LOSER2_ID, self::LOSER_CODE, 0, type: self::BANK_TYPE + 1, softDeleted: true);
        // 標刪列身上還掛著 active 送單列 → 歧義仍在
        $this->insertCashFlowBank(self::LOSER2_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '標刪列仍掛 active cfb 時不得寫入任何資料',
        );
    }

    /** 同一變體重複列出時，停用與審計不得各跑兩次。 */
    public function test_deduplicates_repeated_variant_in_from(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->runRename(['--from' => self::LOSER_ID . ',' . self::LOSER_ID])->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('bank_dedup_logs')
                ->where('action', 'rename_deactivate_bank')
                ->where('loser_bank_id', self::LOSER_ID)
                ->count(),
            '重複列出的變體只能留一筆停用審計',
        );
    }

    /**
     * survivor 若已標 deleted_at，AgentBankSyncService、AvailableBanksAlignService、
     * BackfillStandardBanksCommand 都會當它不存在
     * （三者皆帶 whereNull('deleted_at')）。把全部引用收斂到這種列上等於收斂到一個
     * 同步鏈看不見的目標，故 status=1 還不夠。
     */
    public function test_rejects_when_survivor_is_soft_deleted(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        DB::table('banks')->where('id', self::SURVIVOR_ID)->update(['deleted_at' => now()]);

        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->runRename()->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /**
     * group > 0 收斂後，若 base（group 0）仍有同碼的 active 列，
     * AgentBankSyncService::syncToAgentGroup() 之後會照那個模板在本 group 另建一筆新列，
     * 變體等於重新長回來、收斂無法定案。這是 dry-run 就該讓操作者看到的前置條件。
     */
    public function test_warns_when_base_group_still_has_an_active_row_with_the_variant_code(): void
    {
        $this->insertBank(self::BASE_BANK_ID, self::LOSER_CODE, 1, agentGroupId: 0);
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1, agentGroupId: self::OTHER_GROUP_ID);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1, agentGroupId: self::OTHER_GROUP_ID);

        $this->runRename([
            '--agent-group' => self::OTHER_GROUP_ID,
            '--execute' => false,
        ])
            ->expectsOutputToContain('base（group 0）仍有 active 同碼列')
            ->assertExitCode(0);
    }

    /**
     * dry-run 的分流快照是交易外的無鎖讀，而 sync 隨時在跑：顯示計畫到實際寫入之間，
     * 同一批列可能已被改動，照舊計畫寫下去就是覆蓋別人的變更或撞唯一鍵。
     * 故寫入前必須在交易內重讀並鎖住 banks 與 cash_flow_banks。
     *
     * 真正的競態無法在測試中重現，此處驗的是鎖確實有下，避免日後被靜默拿掉。
     */
    public function test_rebuilds_the_plan_under_row_locks_before_writing(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $lockedSql = [];

        DB::listen(static function ($query) use (&$lockedSql): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $lockedSql[] = $query->sql;
            }
        });

        $this->runRename()->assertExitCode(0);

        $this->assertNotEmpty(
            array_filter($lockedSql, static fn (string $sql): bool => str_contains($sql, 'cash_flow_banks')),
            '寫入前須在交易內鎖住 cash_flow_banks 並重算分流',
        );

        $this->assertNotEmpty(
            array_filter($lockedSql, static fn (string $sql): bool => str_contains($sql, '`banks`')),
            'survivor 與變體的 banks 列也須鎖住，否則會蓋掉 sync 同時間的變更',
        );
    }

    /** 比照 bank:dedup-active，--execute 未帶 --force 時須二次確認；否認即零寫入。 */
    public function test_aborts_without_writing_when_confirmation_declined(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
            '--execute' => true,
        ])
            ->expectsConfirmation(
                sprintf(
                    '確認執行收斂？此操作不可逆，寫入目標 database：%s',
                    DB::connection()->getDatabaseName(),
                ),
                'no',
            )
            ->assertExitCode(0);

        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('cash_flow_banks')->where('id', $cfbId)->value('bank_id'),
            '否認確認後不得 re-point',
        );

        $this->assertSame(
            1,
            (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'),
            '否認確認後不得停用 loser',
        );
    }

    /**
     * DB target 由容器的 -e DB_CONNECTION 決定、不在命令參數裡，各白標 id 區間又重疊，
     * 連錯站時 preflight 全綠仍會寫入。故寫入目標必須在 dry-run 階段就印給操作者看。
     */
    public function test_dry_run_discloses_write_target_database(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
        ])
            // 兩個斷言不可共存於同一行輸出：PendingCommand 逐行消耗，
            // 同一行同時滿足兩個期待時只會消掉第一個，第二個必然落空。
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(0);
    }

    /**
     * 阻斷才是最需要核對環境的時刻：操作者要判斷「這是真的撞鍵，還是我連錯站」。
     * 揭露若排在阻斷判定之後，這三種失敗出口就全都看不到自己打的是哪個庫。
     */
    public function test_blocked_run_still_discloses_write_target_database(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 0);
        $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_SAME', status: 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
        ])
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(1);
    }

    /** preflight 失敗同理：id 打錯與連錯站的症狀一樣（查無此列），沒有環境資訊就分不出是哪一種。 */
    public function test_preflight_failure_still_discloses_write_target_database(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
        ])
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(1);
    }

    /**
     * 全數留置時仍會停用變體列，但報表只有一行「無 cash_flow_banks 需改指」，
     * 容易被讀成「沒事」而非「本次收斂實質為空、配對可能選錯」。
     */
    public function test_warns_when_no_cash_flow_bank_is_repointed(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::LOSER_ID, self::FOREIGN_CODE, self::CASH_FLOW_ID, status: 1);

        $this->artisan('bank:rename-bank', [
            '--agent-group' => self::AGENT_GROUP_ID,
            '--from' => self::LOSER_ID,
            '--to' => self::SURVIVOR_ID,
        ])
            ->expectsOutputToContain('本次零改指')
            ->assertExitCode(0);
    }

    /**
     * 同一渠道的 cfb 撞鍵與 available_banks 並存，兩層的三方碼都不同：
     * 變體 PROV_L、survivor PROV_S。這是 --accept-provider-code 要放行的典型形態。
     *
     * @return int 變體那列 cfb 的 id
     */
    private function seedProviderCodeDivergence(): int
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_S');
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_L');

        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::SURVIVOR_CODE, 'BankCode_Third' => 'PROV_S'],
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'PROV_L'],
        ]);

        return $cfbId;
    }

    private function acceptance(string $loserThird = 'PROV_L', string $survivorThird = 'PROV_S'): string
    {
        return self::CASH_FLOW_ID . ':' . $loserThird . ':' . $survivorThird;
    }

    /**
     * 完全相符就放行並完成收斂：綁定改指、變體停用；被放行的 cfb 留在變體身上且仍啟用，
     * available_banks 的變體碼項目原樣留著，殘留檢查不得誤擋。
     */
    public function test_accepted_provider_code_lets_the_convergence_complete(): void
    {
        $cfbId = $this->seedProviderCodeDivergence();
        $bindingId = $this->insertMemberBinding(990001, self::LOSER_ID, '1111111111');

        $this->runRename(['--accept-provider-code' => [$this->acceptance()]])->assertExitCode(0);

        $this->assertSame(
            self::SURVIVOR_ID,
            (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'),
            '會員綁定應改指 survivor',
        );
        $this->assertSame(0, (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'), '變體應停用');

        $cfb = DB::table('cash_flow_banks')->where('id', $cfbId)->first();

        $this->assertSame(self::LOSER_ID, (int) $cfb->bank_id, '被放行的 cfb 應留置在變體身上');
        $this->assertSame(1, (int) $cfb->status, '被放行的 cfb 不得被停用');

        $this->assertSame(
            [self::SURVIVOR_CODE, self::LOSER_CODE],
            array_column($this->readAvailableBanks(), 'BankCode_ELG'),
            '被放行的 available_banks 變體碼項目應原樣留著',
        );
    }

    /** 放行要留痕：收斂後該渠道改送哪個三方碼是這一步決定的。 */
    public function test_accepted_provider_code_is_audited_per_blocker_on_execute(): void
    {
        $cfbId = $this->seedProviderCodeDivergence();

        $this->runRename(['--accept-provider-code' => [$this->acceptance()]])->assertExitCode(0);

        $details = DB::table('bank_dedup_logs')
            ->where('survivor_bank_id', self::SURVIVOR_ID)
            ->where('action', 'rename_accept_provider_code')
            ->pluck('detail')
            ->map(static fn ($detail): array => json_decode((string) $detail, true))
            ->keyBy('t')
            ->all();

        $this->assertCount(2, $details, 'cfb 與 available_banks 兩筆放行各寫一列審計');
        $this->assertSame($cfbId, $details['cash_flow_banks']['id']);
        $this->assertSame(self::ACF_ID, $details['agent_cash_flows']['id']);
        $this->assertSame('PROV_L', $details['cash_flow_banks']['loser_third']);
        $this->assertSame('PROV_S', $details['agent_cash_flows']['survivor_third']);
    }

    /** 預演要逐筆揭露已放行的阻斷，操作者才核得到放行的是哪一筆。 */
    public function test_dry_run_discloses_accepted_blockers(): void
    {
        $this->seedProviderCodeDivergence();

        $this->runRename(['--execute' => false, '--accept-provider-code' => [$this->acceptance()]])
            ->expectsOutputToContain('已由 --accept-provider-code 放行')
            ->assertExitCode(0);
    }

    /** 列的值與實際三方碼不逐字相同就不算列到，照舊阻斷，並把沒用到的那筆印出來。 */
    public function test_mismatched_acceptance_still_blocks_and_is_reported_as_unused(): void
    {
        $cfbId = $this->seedProviderCodeDivergence();

        $this->runRename(['--accept-provider-code' => [$this->acceptance(survivorThird: 'prov_s')]])
            ->expectsOutputToContain('沒有對上任何阻斷')
            ->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /**
     * 只有 cfb 撞鍵、沒有 available_banks 衝突時，cash_flow_id 對但變體或 survivor 任一邊
     * 三方碼不符都不算列到：放行必須三者逐字相同，不能退化成「同渠道列一筆就放」。
     * 兩個案例都是阻斷、零寫入，所以同一份 fixture 連跑兩次。
     */
    public function test_cfb_acceptance_requires_both_provider_codes_to_match(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_S');
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_L');
        $bindingId = $this->insertMemberBinding(990001, self::LOSER_ID, '1111111111');

        $this->runRename(['--accept-provider-code' => [$this->acceptance(survivorThird: 'PROV_X')]])->assertExitCode(1);
        $this->runRename(['--accept-provider-code' => [$this->acceptance(loserThird: 'PROV_X')]])->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
        $this->assertSame(
            self::LOSER_ID,
            (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'),
            '阻斷時綁定不得改指',
        );
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'rename_accept_provider_code')->where('loser_bank_id', self::LOSER_ID)->count(),
            '阻斷時不得留下放行審計',
        );
    }

    /** 只列 cfb 那層對得上的值、available_banks 那層三方碼不同時，整組照舊零寫入。 */
    public function test_blocks_when_any_blocker_is_left_unlisted(): void
    {
        $this->insertBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, 1);
        $this->insertBank(self::LOSER_ID, self::LOSER_CODE, 1);

        $this->insertCashFlowBank(self::SURVIVOR_ID, self::SURVIVOR_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_S');
        $cfbId = $this->insertCashFlowBank(self::LOSER_ID, self::LOSER_CODE, self::CASH_FLOW_ID, providerBankCode: 'PROV_L');

        $this->seedPayoutChannel([
            ['BankCode_ELG' => self::SURVIVOR_CODE, 'BankCode_Third' => 'AB_S'],
            ['BankCode_ELG' => self::LOSER_CODE, 'BankCode_Third' => 'AB_L'],
        ]);

        $this->runRename(['--accept-provider-code' => [$this->acceptance()]])->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', self::LOSER_ID)->count(), '阻斷時不得留審計');
    }

    /** 放行只認三方碼不同；限額另有不同時仍會改變送單條件，照舊阻斷。 */
    public function test_acceptance_does_not_cover_differing_amount_limits(): void
    {
        $cfbId = $this->seedProviderCodeDivergence();

        DB::table('cash_flow_banks')->where('id', $cfbId)->update(['min_amount' => '50.0000']);

        $this->runRename(['--accept-provider-code' => [$this->acceptance()]])
            ->expectsOutputToContain('min_amount')
            ->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }

    /** 選項格式不合法時直接失敗，不進任何判定。 */
    public function test_rejects_malformed_acceptance_option(): void
    {
        $cfbId = $this->seedProviderCodeDivergence();

        $this->runRename(['--accept-provider-code' => ['abc:PROV_L:PROV_S']])
            ->expectsOutputToContain('--accept-provider-code 格式須為')
            ->assertExitCode(1);

        $this->assertLoserUntouched($cfbId);
    }
}
