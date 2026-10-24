<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlowBank;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 灰度上線前唯讀比對：新路徑（cash_flow_banks 查表）與舊路徑（available_banks JSON）
 * 的代付銀行代碼是否一致。把 provider 納入 allowlist 前的驗證關卡。
 *
 * 【分類】Bank — 灰度開閘前的驗證關卡，開閘完成即退役（退役條件見下方）。
 *
 * 使用方式：
 *   php artisan cash-flow:verify-payout-bank-parity
 *   php artisan cash-flow:verify-payout-bank-parity --cash-flow-id=91
 *   php artisan cash-flow:verify-payout-bank-parity --classify-gaps
 *
 * `--classify-gaps`：把硬 gate 2 的違規（JSON 有三方碼、查表側查無）逐筆分成兩類——
 *  - 潛伏斷付：ACF 服務中，且該銀行碼在 ACF 所屬代理群組仍有 `status=1` 的 banks 列。
 *    會員今天綁得到、選得到這家銀行，一旦該金流商切成查表就送不出碼而擋單。
 *  - 死 entry：ACF 已停用，或該碼在該群組已無活列。JSON 裡的項目沒有任何消費者。
 *  另標出 cfb 側是「無列」還是「已停用」，前者要補列（`cash-flow:sync`）、後者要查為何被停。
 *  判準只看「ACF 所屬群組」——cash_flow_banks 不分群組，但會員能不能選到這家銀行是看自己群組。
 *
 * 摘要的筆數另外依 ACF 是否啟用拆成兩行（啟用中的通道／停用的通道）。看營運面的規模時
 * 讀「啟用中的通道」那一行；停用的通道不排除，理由見 runParityComparison()。
 *
 * 安全性：唯讀，禁止任何寫入（update/insert/delete）。
 *
 * 效能：逐銀行查表（N+1），生產量級單站全掃要數分鐘（TPower 實測）；日常操作一律帶
 * --cash-flow-id 縮範圍，全掃只在灰度前驗收用。
 *
 * ## 退役條件
 *
 * 當全部代付金流商都進了 `PAYOUT_BANK_LOOKUP_PROVIDERS`、舊的
 * `convertPayoutBankCodeFromJson()` 路徑退場之後，兩條路徑只剩一條、parity 無從比起
 * ⇒ 可連同舊路徑一併刪除。這是本命令唯一的存在理由。
 */
class VerifyPayoutBankParityCommand extends Command
{
    private const string RISK_LATENT_BREAK = '潛伏斷付';

    private const string RISK_DEAD_ENTRY = '死 entry';

    protected $signature = 'cash-flow:verify-payout-bank-parity
        {--cash-flow-id= : 只驗特定 cash_flow_id}
        {--classify-gaps : 對硬 gate 2 違規逐筆分類（潛伏斷付／死 entry），並標出 cfb 側是無列還是已停用}';

    protected $description = '灰度上線前唯讀比對：新路徑（cash_flow_banks）與舊路徑（available_banks JSON）的代付銀行代碼是否一致';

    public function handle(): int
    {
        $cashFlowIdFilter = $this->option('cash-flow-id') !== null
            ? (int) $this->option('cash-flow-id')
            : null;

        // 硬 gate 1：(cash_flow_id, currency_id, bank_code) status=1 有多列 → 資料異常
        // cash_flow_banks 唯一鍵為 (cash_flow_id, currency_id, bank_id) 不含 bank_code，
        // runtime 雖用 orderBy('id') 保確定序，但多列本身仍是資料異常，必須照出。
        $gate1Passed = $this->runDuplicateKeyGate($cashFlowIdFilter);

        // 硬 gate 3：cash_flow_id（fallback 後仍 null）或 currency_id 為 null
        // → 新路徑 WHERE 恆偽，直接擋單
        $gate3Passed = $this->runNullColumnGate($cashFlowIdFilter);

        // 主比對（同時收集 gate 2：表覆蓋 ⊇ JSON）
        [$totalBanks, $parityMismatches, $gate2Violations, $countsByAcfStatus] = $this->runParityComparison($cashFlowIdFilter);
        $gate2Passed = empty($gate2Violations);

        // 輸出 parity 不一致明細（warn 標紅，不影響 exit code）
        if (!empty($parityMismatches)) {
            $this->warn('== parity 不一致 ==');
            $this->table(
                ['cash_flow_id', 'currency_id', 'BankCode_ELG', 'JSON', 'cash_flow_banks'],
                $parityMismatches,
            );
        }

        $this->line(sprintf('比對 %d 筆 bank 對應，%d 筆不一致', $totalBanks, count($parityMismatches)));
        // 兩行分開印：同一行塞兩組數字，測試的 expectsOutputToContain 會互搶同一行輸出
        $this->line(sprintf('  啟用中的通道：%d 筆，%d 筆不一致', $countsByAcfStatus['active']['total'], $countsByAcfStatus['active']['mismatch']));
        $this->line(sprintf('  停用的通道：%d 筆，%d 筆不一致', $countsByAcfStatus['inactive']['total'], $countsByAcfStatus['inactive']['mismatch']));

        if ($this->option('classify-gaps')) {
            $this->classifyGaps($gate2Violations);
        }

        if ($gate1Passed && $gate2Passed && $gate3Passed) {
            $this->info('硬 gate 全數通過');

            return self::SUCCESS;
        }

        $this->error('硬 gate 未全數通過，請檢查上方明細');

        return self::FAILURE;
    }

    /**
     * 硬 gate 1：掃描 cash_flow_banks 中 (cash_flow_id, currency_id, bank_code) status=1 有多列者。
     *
     * 這裡的 GROUP BY 刻意維持一般（大小寫不敏感）比對，與上面查表用的 BINARY 不同：
     * 大小寫變體其實不會被 runtime 判為同一個碼，嚴格說不算「同鍵多列」，但它本身就是
     * 該在開閘前被人看到的資料問題。寬鬆分組是保守的超集——真重複與大小寫變體都會擋下，
     * 方向是多擋不是少擋。若哪天要改精確，記得那會把大小寫變體從「擋住」變成「放行」。
     */
    private function runDuplicateKeyGate(?int $cashFlowIdFilter): bool
    {
        $duplicates = DB::table('cash_flow_banks')
            ->select(['cash_flow_id', 'currency_id', 'bank_code', DB::raw('COUNT(*) as cnt')])
            ->where('status', 1)
            ->when($cashFlowIdFilter !== null, fn ($q) => $q->where('cash_flow_id', $cashFlowIdFilter))
            ->groupBy('cash_flow_id', 'currency_id', 'bank_code')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return true;
        }

        $this->warn('== 硬 gate 1：同鍵多列（(cash_flow_id, currency_id, bank_code) status=1 > 1 筆）==');
        $this->table(
            ['cash_flow_id', 'currency_id', 'bank_code', 'count'],
            $duplicates->map(fn ($r) => [
                'cash_flow_id' => $r->cash_flow_id,
                'currency_id' => $r->currency_id,
                'bank_code' => $r->bank_code,
                'count' => $r->cnt,
            ])->all(),
        );

        return false;
    }

    /**
     * 硬 gate 3：掃描 agent_cash_flows 中 cash_flow_id（fallback 後仍 null）或 currency_id 為 null 者。
     * null 值在新路徑 WHERE 查詢中恆偽，導致擋單。
     */
    private function runNullColumnGate(?int $cashFlowIdFilter): bool
    {
        $nullRows = DB::table('agent_cash_flows')
            ->when($cashFlowIdFilter !== null, fn ($q) => $q->where('cash_flow_id', $cashFlowIdFilter))
            ->where(function ($q): void {
                $q->whereNull('cash_flow_id')->orWhereNull('currency_id');
            })
            ->select(['id', 'cash_flow_id', 'currency_id'])
            ->get();

        if ($nullRows->isEmpty()) {
            return true;
        }

        $this->warn('== 硬 gate 3：cash_flow_id（fallback 後仍 null）或 currency_id 為 null 的 agent_cash_flows ==');
        $this->table(
            ['id', 'cash_flow_id', 'currency_id'],
            $nullRows->map(fn ($r) => [
                'id' => $r->id,
                'cash_flow_id' => $r->cash_flow_id ?? 'NULL',
                'currency_id' => $r->currency_id ?? 'NULL',
            ])->all(),
        );

        return false;
    }

    /**
     * 主比對：逐 available_banks[] 比對 JSON BankCode_Third vs cash_flow_banks 查表結果。
     *
     * 查表邏輯與 WithdrawalPayoutTrait::lookupProviderBankCodeFromTable 同構：
     *  - cash_flow_id fallback：$acf->cash_flow_id ?? $acf->cashFlow?->id
     *  - orderBy('id') 取確定序（多列時穩定取最早建立列）
     *  - status=1 篩選
     *  - bank_code 以 BINARY 逐字比對（見下段）
     *
     * 比較規則：嚴格 !==，只做 (string) cast，不 trim 不數值正規化——本命令是驗證器，
     * 預先正規化會蓋掉它該抓出來的問題。
     *
     * ⚠️ bank_code 的比對方式必須與 runtime 一模一樣，否則等於用寬鬆的尺去量嚴格的路徑，
     * 會給出「parity 通過」但實際出款查無碼的假保證。兩條 runtime 路徑已於 2026-09-09
     * 收斂為「逐字相同才算命中」（原本 JSON 路徑的 == 會把數字字串強制轉型、查表路徑的
     * SQL = 因 utf8mb4_unicode_ci 而大小寫不敏感，判定方向相反），本處同步跟上。
     *
     * 同時收集 gate 2：JSON 有非空 BankCode_Third 但表查無（灰度後會擋單）。
     * gate 2 列除了三個鍵，另帶 ACF 脈絡（acf_id／acf_status／agent_group_id／JSON 三方碼）
     * 供 `--classify-gaps` 分類；印表時只取原本三欄，輸出格式不變。
     *
     * 筆數另依 ACF 是否啟用分開累計（countsByAcfStatus）。停用的 ACF 刻意不排除：停用的通道
     * 可能重新啟用，啟用前它的項目仍要對得上，排除掉就成了盲區。分開計數是為了讓
     * 「啟用中的通道」那一組能單獨當營運面的數字看——停用通道的項目沒有任何會員在用，
     * 混在總數裡會把不一致的規模灌大（例如 VWorld 停用通道的項目約佔全部的五成）。
     *
     * @return array{int, list<array<string, mixed>>, list<array<string, mixed>>, array{active: array{total: int, mismatch: int}, inactive: array{total: int, mismatch: int}}} [totalBanks, parityMismatches, gate2Violations, countsByAcfStatus]
     */
    private function runParityComparison(?int $cashFlowIdFilter): array
    {
        $totalBanks = 0;
        $parityMismatches = [];
        $gate2Violations = [];
        $countsByAcfStatus = [
            'active' => ['total' => 0, 'mismatch' => 0],
            'inactive' => ['total' => 0, 'mismatch' => 0],
        ];

        $query = AgentCashFlow::query()
            ->when($cashFlowIdFilter !== null, fn ($q) => $q->where('cash_flow_id', $cashFlowIdFilter));

        foreach ($query->cursor() as $acf) {
            // 解析 info，相容 array cast 與 JSON string（不依賴 model cast 設定）
            $raw = $acf->info;
            $info = is_array($raw) ? $raw : (array) json_decode((string) $raw, true);
            $availableBanks = $info['available_banks'] ?? [];

            $acfStatusKey = (int) $acf->status === 1 ? 'active' : 'inactive';

            // 計算有效 cash_flow_id，比照 lookupProviderBankCodeFromTable 同構 fallback
            $effectiveCashFlowId = $acf->cash_flow_id;
            if ($effectiveCashFlowId === null) {
                // cash_flow_id 欄位為 null 時，明確 load 關聯避免觸發 preventLazyLoading 禁令
                $acf->load('cashFlow');
                $effectiveCashFlowId = $acf->cashFlow?->id;
            }

            foreach ($availableBanks as $bank) {
                $bankCodeElg = (string) ($bank['BankCode_ELG'] ?? '');
                if ($bankCodeElg === '') {
                    continue;
                }

                $totalBanks++;
                $countsByAcfStatus[$acfStatusKey]['total']++;

                $jsonValue = (string) ($bank['BankCode_Third'] ?? '');

                // 查表：同構 lookupProviderBankCodeFromTable（BINARY 逐字比對、orderBy id 確定序、status=1）
                $tableValue = (string) CashFlowBank::query()
                    ->where('cash_flow_id', $effectiveCashFlowId)
                    ->where('currency_id', $acf->currency_id)
                    ->whereRaw('BINARY bank_code = ?', [$bankCodeElg])
                    ->where('status', 1)
                    ->orderBy('id')
                    ->value('provider_bank_code');

                // 嚴格字串比較（!==），只做 (string) cast，不 trim 不數值正規化
                if ($jsonValue !== $tableValue) {
                    $countsByAcfStatus[$acfStatusKey]['mismatch']++;
                    $parityMismatches[] = [
                        'cash_flow_id' => $effectiveCashFlowId ?? 'NULL',
                        'currency_id' => $acf->currency_id ?? 'NULL',
                        'BankCode_ELG' => $bankCodeElg,
                        'JSON' => $jsonValue !== '' ? $jsonValue : '(空)',
                        'cash_flow_banks' => $tableValue !== '' ? $tableValue : '(查無)',
                    ];

                    // Gate 2：JSON 有非空 BankCode_Third 但表查無 → 灰度後擋單（金流紅線）
                    if ($jsonValue !== '' && $tableValue === '') {
                        // id 明確轉 int：emulate prepares 連線會把整數欄讀成字串，後續 is_int 過濾會漏
                        $gate2Violations[] = [
                            'cash_flow_id' => $effectiveCashFlowId !== null ? (int) $effectiveCashFlowId : 'NULL',
                            'currency_id' => $acf->currency_id !== null ? (int) $acf->currency_id : 'NULL',
                            'BankCode_ELG' => $bankCodeElg,
                            'acf_id' => (int) $acf->id,
                            'acf_status' => (int) $acf->status,
                            'agent_group_id' => (int) $acf->agent_group_id,
                            'json_third' => $jsonValue,
                        ];
                    }
                }
            }
        }

        if (!empty($gate2Violations)) {
            $this->warn('== 硬 gate 2：JSON 有 BankCode_Third 但 cash_flow_banks 查無（灰度後會擋單）==');
            $this->table(
                ['cash_flow_id', 'currency_id', 'BankCode_ELG'],
                array_map(
                    static fn (array $v): array => [
                        'cash_flow_id' => $v['cash_flow_id'],
                        'currency_id' => $v['currency_id'],
                        'BankCode_ELG' => $v['BankCode_ELG'],
                    ],
                    $gate2Violations,
                ),
            );
        }

        return [$totalBanks, $parityMismatches, $gate2Violations, $countsByAcfStatus];
    }

    /**
     * `--classify-gaps`：把 gate 2 違規逐筆分類。
     *
     * 判準：
     *  - 潛伏斷付 ＝ ACF `status=1` 且該 BankCode_ELG 在 ACF 所屬 agent_group_id、同幣別
     *    有 `status=1` 的 banks 列（會員選得到卻查不到碼）。刻意不看 `deleted_at`——會員端
     *    `WithdrawalController::getBanks` 只篩群組＋`status=1`，軟刪但仍 `status=1` 的列會員照樣選得到。
     *  - 死 entry ＝ 其餘。
     *  - cfb_state：該 (cash_flow_id, currency_id, bank_code) 在 cash_flow_banks 完全無列＝「無列」；
     *    有列但都不是 status=1＝「已停用」（gate 2 已保證沒有 status=1 的列）。
     *
     * 為避免逐筆查 DB，先把候選鍵一次撈回、在 PHP 端以小寫鍵比對（與 utf8mb4_unicode_ci 的
     * 大小寫不敏感語意一致；寧可多判成有活列，也不漏報）。
     *
     * @param  list<array<string, mixed>>  $violations
     */
    private function classifyGaps(array $violations): void
    {
        $this->line('');
        if (empty($violations)) {
            $this->info('== gate 2 分類：無違規可分類 ==');

            return;
        }

        $activeBankKeys = $this->loadActiveGroupBankKeys($violations);
        $existingCfbKeys = $this->loadExistingCfbKeys($violations);

        $rows = [];
        $riskCounts = [self::RISK_LATENT_BREAK => 0, self::RISK_DEAD_ENTRY => 0];
        $byCashFlow = [];

        foreach ($violations as $v) {
            $groupHasActiveBank = isset($activeBankKeys[$this->bankKey(
                (int) $v['agent_group_id'],
                $v['currency_id'],
                (string) $v['BankCode_ELG'],
            )]);
            $cfbExists = isset($existingCfbKeys[$this->cfbKey(
                $v['cash_flow_id'],
                $v['currency_id'],
                (string) $v['BankCode_ELG'],
            )]);

            $risk = ((int) $v['acf_status'] === 1 && $groupHasActiveBank)
                ? self::RISK_LATENT_BREAK
                : self::RISK_DEAD_ENTRY;

            $riskCounts[$risk]++;
            $cashFlowKey = (string) $v['cash_flow_id'];
            $byCashFlow[$cashFlowKey][$risk] = ($byCashFlow[$cashFlowKey][$risk] ?? 0) + 1;

            $rows[] = [
                'acf_id' => $v['acf_id'],
                'acf_status' => $v['acf_status'],
                'agent_group_id' => $v['agent_group_id'],
                'cash_flow_id' => $v['cash_flow_id'],
                'currency_id' => $v['currency_id'],
                'BankCode_ELG' => $v['BankCode_ELG'],
                'JSON' => $v['json_third'],
                'group_active_bank' => $groupHasActiveBank ? '有' : '無',
                'cfb_state' => $cfbExists ? '已停用' : '無列',
                'risk' => $risk,
            ];
        }

        $this->warn('== gate 2 分類（潛伏斷付＝ACF 服務中且該碼在所屬群組仍有活列）==');
        $this->table(
            ['acf_id', 'acf_status', 'agent_group_id', 'cash_flow_id', 'currency_id', 'BankCode_ELG', 'JSON', 'group_active_bank', 'cfb_state', 'risk'],
            $rows,
        );

        $this->line(sprintf(
            '分類彙總：%s %d 筆、%s %d 筆（共 %d 筆）',
            self::RISK_LATENT_BREAK,
            $riskCounts[self::RISK_LATENT_BREAK],
            self::RISK_DEAD_ENTRY,
            $riskCounts[self::RISK_DEAD_ENTRY],
            count($violations),
        ));

        ksort($byCashFlow, SORT_NATURAL);
        $this->table(
            ['cash_flow_id', self::RISK_LATENT_BREAK, self::RISK_DEAD_ENTRY],
            array_map(
                static fn (string $cashFlowId, array $counts): array => [
                    'cash_flow_id' => $cashFlowId,
                    self::RISK_LATENT_BREAK => $counts[self::RISK_LATENT_BREAK] ?? 0,
                    self::RISK_DEAD_ENTRY => $counts[self::RISK_DEAD_ENTRY] ?? 0,
                ],
                array_keys($byCashFlow),
                $byCashFlow,
            ),
        );
    }

    /**
     * 一次撈回違規牽涉到的 (agent_group_id, currency_id, code) 服務中 banks 列，回傳小寫鍵集合。
     *
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, true>
     */
    private function loadActiveGroupBankKeys(array $violations): array
    {
        $groupIds = array_values(array_unique(array_map(static fn (array $v): int => (int) $v['agent_group_id'], $violations)));
        $currencyIds = array_values(array_unique(array_filter(
            array_map(static fn (array $v): mixed => $v['currency_id'], $violations),
            static fn (mixed $id): bool => is_int($id),
        )));
        $codes = array_values(array_unique(array_map(static fn (array $v): string => (string) $v['BankCode_ELG'], $violations)));

        if ($currencyIds === []) {
            return [];
        }

        $keys = [];
        Bank::query()
            ->whereIn('agent_group_id', $groupIds)
            ->whereIn('currency_id', $currencyIds)
            ->whereIn('code', $codes)
            ->where('status', 1)
            ->select(['agent_group_id', 'currency_id', 'code'])
            ->each(function (Bank $bank) use (&$keys): void {
                $keys[$this->bankKey((int) $bank->agent_group_id, $bank->currency_id, (string) $bank->code)] = true;
            });

        return $keys;
    }

    /**
     * 一次撈回違規牽涉到的 (cash_flow_id, currency_id, bank_code) cash_flow_banks 列（不限 status），回傳小寫鍵集合。
     *
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, true>
     */
    private function loadExistingCfbKeys(array $violations): array
    {
        $cashFlowIds = array_values(array_unique(array_filter(
            array_map(static fn (array $v): mixed => $v['cash_flow_id'], $violations),
            static fn (mixed $id): bool => is_int($id),
        )));
        $currencyIds = array_values(array_unique(array_filter(
            array_map(static fn (array $v): mixed => $v['currency_id'], $violations),
            static fn (mixed $id): bool => is_int($id),
        )));
        $codes = array_values(array_unique(array_map(static fn (array $v): string => (string) $v['BankCode_ELG'], $violations)));

        if ($cashFlowIds === [] || $currencyIds === []) {
            return [];
        }

        $keys = [];
        CashFlowBank::query()
            ->whereIn('cash_flow_id', $cashFlowIds)
            ->whereIn('currency_id', $currencyIds)
            ->whereIn('bank_code', $codes)
            ->select(['cash_flow_id', 'currency_id', 'bank_code'])
            ->each(function (CashFlowBank $row) use (&$keys): void {
                $keys[$this->cfbKey($row->cash_flow_id, $row->currency_id, (string) $row->bank_code)] = true;
            });

        return $keys;
    }

    private function bankKey(int $agentGroupId, mixed $currencyId, string $code): string
    {
        return $agentGroupId . '|' . (string) $currencyId . '|' . mb_strtolower($code);
    }

    private function cfbKey(mixed $cashFlowId, mixed $currencyId, string $bankCode): string
    {
        return (string) $cashFlowId . '|' . (string) $currencyId . '|' . mb_strtolower($bankCode);
    }
}
