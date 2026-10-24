<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlowTypeEnum;
use App\Enums\DriftSubjectEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\CashFlowBank;
use App\Models\DriftBaseline;
use App\Services\Payment\Providers\FPay\WithdrawBankList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 每天向 FPay 查各代付設定的現行銀行清單，與上一次的基準快照比對，代號有變就告警。
 *
 * 【分類】Maintenance — 唯讀稽核，不動任何業務資料；只寫自己的快照表。
 *
 * ## 在防什麼
 * FPay 的銀行代號是按「商戶帳號 × 幣別」各發一套，而且**會在不通知的情況下整批換掉**
 * （target/op_tickets#3174）。換掉之後有兩種後果，後者嚴重得多：
 *
 * 1. 我方的代號在 FPay 那邊不存在了 → 送出被退回「bank id invalid」，提款失敗但錢沒跑掉。
 * 2. 我方的代號還在，**但已經是別家銀行**了 → 送出不會被退回，**錢直接匯到別家銀行**。
 *
 * 第 2 種只能靠「這個代號上次是哪家銀行、這次是哪家」看得出來，所以本命令存快照。
 *
 * ## 為什麼比的是快照、不是我方的銀行名稱
 * 我方 `banks.name`（例 `(IDR) - Bank Mandiri`）與 FPay 回的名稱（例 `Mandiri Bank`）
 * 是各自獨立的兩套寫法，逐字比對必然全部不一致，比了只會天天誤報。快照比對的兩邊
 * 都是 FPay 自己回的字，格式天生一致，也因此適用所有商戶帳號與幣別，不限單一站點。
 *
 * ## 兩道互補的檢查，缺一不可
 * - **與基準快照比**：抓「FPay 改了什麼」。代號改名、代號消失都在這裡現形。
 * - **我方在用的代號還在不在清單上**：抓「我方現在壞不壞」。查兩個存放處——代付設定
 *   `agent_cash_flows.info.available_banks` 的 `BankCode_Third`（代付實際讀的就是這裡），
 *   以及 `cash_flow_banks` 啟用中列的 `provider_bank_code`（查表路徑與重建 available_banks 的來源）。
 *
 * 前者只在「變動的那一次」看得到，後者只要沒修好就會一直報，兩道合起來才不會漏。
 *
 * ## 差異未處理前不換基準
 * 偵測到差異時**刻意不更新基準快照**，於是每天都會再報一次，直到有人處理。
 * 若每次都無條件更新基準，改名就只會在變動當天報一次，那天沒人看到就再也抓不到了。
 * 確認差異已處理（或確認是 FPay 正常改名、我方不必動）之後，跑 `--accept` 接受新基準。
 *
 * 「只多了幾家銀行」不在此列：新增銀行不是風險，基準照常跟上，否則那幾家永遠不在基準裡，
 * 日後被改名也偵測不到。
 *
 * ## 三方查詢失敗不會立刻吵
 * 每天打一次三方 API，偶發失敗屬正常。連續失敗達門檻才告警，成功一次就把計數歸零。
 *
 * ## 冪等性與重跑安全性
 * **重跑安全。** 不寫任何業務資料表，唯一會寫的是自己的快照表，而且**只在清單內容與
 * 基準不同時才寫一列**。清單沒變的日子一列都不會新增，那些日子的執行等同唯讀。
 *
 * ## 關聯
 * - 基準存放與接入契約：{@see DriftBaseline}（通用表，本命令是首個監測對象；
 *   別的三方設定或我方設定要做同樣的守護，新增一個 {@see DriftSubjectEnum} case 即可）
 * - 三方查詢實作：`app/Services/Payment/Providers/FPay/WithdrawBankList.php`
 * - 人工查單次清單：`cash-flow:third-party-query <代付設定 id> --raw`
 * - 修 TPower 印尼盾那批代號：`cash-flow:remap-tpower-fpay-idr-bankcodes`
 *
 * 用法：
 *   php artisan cash-flow:audit-fpay-bank-list                # 比對並印報表
 *   php artisan cash-flow:audit-fpay-bank-list --notify       # 排程模式：有差異即告警
 *   php artisan cash-flow:audit-fpay-bank-list --acf=272      # 只查單一代付設定
 *   php artisan cash-flow:audit-fpay-bank-list --accept       # 確認差異已處理，接受新基準
 *
 * @knowledge rules/payout-bank-code-mapping
 */
class AuditFpayBankList extends Command
{
    /** FPay 在 `cash_flows.third_party_code` 的識別碼；代付與代收各一支，本命令只看代付 */
    public const string CASH_FLOW_THIRD_PARTY_CODE = 'W_FPAY';

    /** 連續查詢失敗達這個次數才告警：每天只跑一次，偶發失敗不值得吵醒任何人 */
    private const int FAILURE_ALERT_THRESHOLD = 2;

    /** 失敗計數器存活秒數（3 天）：足以跨過兩次每日執行，又不會讓很久以前的失敗累計進來 */
    private const int FAILURE_COUNTER_TTL = 259200;

    protected $signature = 'cash-flow:audit-fpay-bank-list
                            {--acf= : 只查指定代付設定的 agent_cash_flows.id（省略＝本站所有啟用中的 FPay 代付設定）}
                            {--accept : 人工確認差異已處理，把這次查到的清單存為新的比對基準}
                            {--notify : 排程模式：有差異即 BotNotify，且一律回 SUCCESS（差異不算命令失敗）}';

    protected $description = '每天比對 FPay 代付銀行清單與基準快照，代號消失或換成別家銀行即告警（不動業務資料）';

    public function handle(): int
    {
        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->info('本站沒有啟用中的 FPay 代付設定，無需稽核');

            return self::SUCCESS;
        }

        $reports = [];

        foreach ($targets as $agentCashFlow) {
            $reports[] = $this->auditOne($agentCashFlow);
        }

        $this->render($reports);

        $hasProblem = $this->hasProblem($reports);

        if ($this->option('notify')) {
            $this->notify($reports);

            // 有差異不算命令失敗：排程每天紅會讓真正的執行失敗被淹沒，差異一律交 BotNotify 傳達
            return self::SUCCESS;
        }

        return $hasProblem ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 本站所有啟用中的 FPay 代付設定。
     *
     * FPay 的代號按商戶帳號各發一套，不同代付設定必須各查各的、各比各的，不可互相推定。
     *
     * @return list<AgentCashFlow>
     */
    private function resolveTargets(): array
    {
        $query = AgentCashFlow::query()
            ->where('status', 1)
            ->whereHas('cashFlow', fn ($builder) => $builder
                ->where('third_party_code', self::CASH_FLOW_THIRD_PARTY_CODE)
                ->where('type', CashFlowTypeEnum::WITHDRAW->value))
            ->with('currency')
            ->orderBy('id');

        if (($acfId = $this->option('acf')) !== null) {
            $query->where('id', (int) $acfId);
        }

        return $query->get()->all();
    }

    /**
     * 稽核單一代付設定：查清單 → 與基準比 → 檢查我方在用的代號 → 視情況存快照。
     *
     * @return array{acf_id: int, acf_name: string, currency: string, error: ?string, baseline_at: ?string, bank_count: int, renamed: list<array{code: string, was: string, now: string}>, removed: list<array{code: string, was: string}>, added: int, acf_missing: list<string>, cfb_missing: list<string>, baseline_created: bool, baseline_accepted: bool}
     */
    private function auditOne(AgentCashFlow $agentCashFlow): array
    {
        $acfId = (int) $agentCashFlow->id;
        $report = [
            'acf_id' => $acfId,
            'acf_name' => (string) $agentCashFlow->name,
            'currency' => (string) ($agentCashFlow->currency?->code ?? '-'),
            'error' => null,
            'baseline_at' => null,
            'bank_count' => 0,
            'renamed' => [],
            'removed' => [],
            'added' => 0,
            'acf_missing' => [],
            'cfb_missing' => [],
            'baseline_created' => false,
            'baseline_accepted' => false,
        ];

        $current = $this->fetchBankList($agentCashFlow);

        if ($current === null) {
            $report['error'] = $this->recordFailure($acfId);

            return $report;
        }

        $this->clearFailure($acfId);
        $report['bank_count'] = count($current);

        $baseline = DriftBaseline::query()
            ->forScope(DriftSubjectEnum::FPAY_WITHDRAW_BANK_LIST, $acfId)
            ->first();

        if ($baseline === null) {
            // 第一次跑：沒有可比的對象，只建立基線，不告警——把「沒有歷史」報成異常毫無意義
            $this->storeSnapshot($agentCashFlow, $current);
            $report['baseline_created'] = true;

            return $report;
        }

        $report['baseline_at'] = $baseline->created_at?->toDateTimeString();
        $diff = $this->diffAgainstBaseline($current, $baseline->payload);
        $report['renamed'] = $diff['renamed'];
        $report['removed'] = $diff['removed'];
        $report['added'] = $diff['added'];

        $ourCodes = $this->checkOurCodes($agentCashFlow, $current);
        $report['acf_missing'] = $ourCodes['acf_missing'];
        $report['cfb_missing'] = $ourCodes['cfb_missing'];

        $hasRisk = $diff['renamed'] !== [] || $diff['removed'] !== [];

        // 不寫新列的兩種情形：
        // 一是清單與基準逐筆相同，寫了也是同一份、徒然長表，而且會讓例行執行變成寫入
        // （本機手動跑就得多帶寫入用的連接埠）；
        // 二是有風險差異但還沒有人確認，不換基準才會每天再報一次。
        // 只是多了幾家銀行（沒有改名也沒有消失）則照常更新，否則那幾家永遠不在基準裡，
        // 日後被改名也偵測不到。
        if (!$this->isSameAsBaseline($current, $baseline->payload) && (!$hasRisk || $this->option('accept'))) {
            $this->storeSnapshot($agentCashFlow, $current);
            $report['baseline_accepted'] = $hasRisk && $this->option('accept');
        }

        return $report;
    }

    /**
     * 向 FPay 查該代付設定的銀行清單。
     *
     * 缺代號或缺名稱的列一律視為異常整份退回，不靜默略過：靜默略過會讓「FPay 少回了東西」
     * 看起來像「那家銀行本來就不在清單上」。
     *
     * @return array<array-key, string>|null 銀行代號 => 銀行名稱；查詢或形狀有問題時回 null
     */
    private function fetchBankList(AgentCashFlow $agentCashFlow): ?array
    {
        try {
            $rows = (new WithdrawBankList($agentCashFlow))->handle();
        } catch (Throwable $e) {
            Log::warning('FPay 代付銀行清單稽核：查詢失敗', [
                'agent_cash_flow_id' => (int) $agentCashFlow->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        $banks = [];

        foreach ($rows as $row) {
            $code = $row['id'] ?? null;
            $name = $row['bank_name'] ?? null;

            if ((!is_string($code) && !is_int($code)) || (string) $code === '' || !is_string($name) || $name === '') {
                Log::warning('FPay 代付銀行清單稽核：清單有缺代號或缺銀行名稱的列', [
                    'agent_cash_flow_id' => (int) $agentCashFlow->id,
                ]);

                return null;
            }

            $banks[(string) $code] = $name;
        }

        return $banks === [] ? null : $banks;
    }

    /**
     * 與基準快照比對。
     *
     * `renamed`＝代號還在但銀行換了（照舊送出錢會匯到別家銀行，最危險）；
     * `removed`＝代號從清單上消失（送出會被退回）；`added` 只計數，新增銀行不是風險。
     *
     * ! 名稱比對逐字相同，不做大小寫或空白正規化：兩邊都是 FPay 自己回的字，
     * ! 正規化只會把「FPay 真的改了名」這種該看到的變動吃掉。
     *
     * @param  array<array-key, string>  $current
     * @param  array<array-key, string>  $baseline
     * @return array{renamed: list<array{code: string, was: string, now: string}>, removed: list<array{code: string, was: string}>, added: int}
     */
    private function diffAgainstBaseline(array $current, array $baseline): array
    {
        $renamed = [];
        $removed = [];

        foreach ($baseline as $code => $wasName) {
            $nowName = $current[$code] ?? null;

            if ($nowName === null) {
                $removed[] = ['code' => (string) $code, 'was' => (string) $wasName];

                continue;
            }

            if ($nowName !== (string) $wasName) {
                $renamed[] = ['code' => (string) $code, 'was' => (string) $wasName, 'now' => $nowName];
            }
        }

        return [
            'renamed' => $renamed,
            'removed' => $removed,
            'added' => count(array_diff_key($current, $baseline)),
        ];
    }

    /**
     * 清單與基準是否逐筆相同（含 FPay 新增的銀行）。
     *
     * 先排序再嚴格比較：FPay 回傳的順序不保證固定，順序不同不代表內容變了。
     * 不用鬆散比較（==）是因為它遇到兩邊都是數值字串的值會改走數值比較；這裡比的是
     * 銀行名稱、實務上碰不到，但沒有理由留這個縫。參數是傳值，排序不影響呼叫端。
     *
     * @param  array<array-key, string>  $current
     * @param  array<array-key, string>  $baseline
     */
    private function isSameAsBaseline(array $current, array $baseline): bool
    {
        ksort($current);
        ksort($baseline);

        return $current === $baseline;
    }

    /**
     * 檢查我方兩個存放處在用的代號，是否還在 FPay 的現行清單上。
     *
     * 兩處都要查：代付送出時讀的是代付設定的 `available_banks`，而 `cash_flow_banks` 是
     * 查表路徑讀的、也是重建 `available_banks` 的來源——只修一邊，另一邊遲早把它蓋回去。
     *
     * @param  array<array-key, string>  $current
     * @return array{acf_missing: list<string>, cfb_missing: list<string>}
     */
    private function checkOurCodes(AgentCashFlow $agentCashFlow, array $current): array
    {
        $acfMissing = [];

        foreach ($agentCashFlow->info['available_banks'] ?? [] as $entry) {
            $code = is_array($entry) ? (string) ($entry['BankCode_Third'] ?? '') : '';

            if ($code !== '' && !isset($current[$code])) {
                $acfMissing[$code] = true;
            }
        }

        $cfbMissing = [];

        $cashFlowBankCodes = CashFlowBank::query()
            ->where('cash_flow_id', (int) $agentCashFlow->cash_flow_id)
            ->where('currency_id', (int) $agentCashFlow->currency_id)
            ->where('status', 1)
            ->pluck('provider_bank_code');

        foreach ($cashFlowBankCodes as $code) {
            $code = (string) $code;

            if ($code !== '' && !isset($current[$code])) {
                $cfbMissing[$code] = true;
            }
        }

        $acfMissing = array_keys($acfMissing);
        $cfbMissing = array_keys($cfbMissing);
        sort($acfMissing);
        sort($cfbMissing);

        return ['acf_missing' => $acfMissing, 'cfb_missing' => $cfbMissing];
    }

    /**
     * @param  array<array-key, string>  $banks
     */
    private function storeSnapshot(AgentCashFlow $agentCashFlow, array $banks): void
    {
        DriftBaseline::query()->create([
            'subject_key' => DriftSubjectEnum::FPAY_WITHDRAW_BANK_LIST->value,
            // scope_key 是 varchar，一律字串綁定（見 DriftBaseline 的接入契約）
            'scope_key' => (string) $agentCashFlow->id,
            'scope_label' => (string) ($agentCashFlow->currency?->code ?? ''),
            'item_count' => count($banks),
            'payload' => $banks,
        ]);
    }

    /**
     * 記一次查詢失敗，回傳要呈現的訊息；連續失敗未達門檻時回 null 代表先不吵。
     */
    private function recordFailure(int $acfId): ?string
    {
        $key = $this->failureKey($acfId);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, self::FAILURE_COUNTER_TTL);

        if ($count < self::FAILURE_ALERT_THRESHOLD) {
            return null;
        }

        return sprintf('連續 %d 次查不到 FPay 銀行清單', $count);
    }

    private function clearFailure(int $acfId): void
    {
        Cache::forget($this->failureKey($acfId));
    }

    private function failureKey(int $acfId): string
    {
        return 'cash-flow:audit-fpay-bank-list:failures:' . $acfId;
    }

    /**
     * 有沒有需要有人去看的東西。查詢失敗未達告警門檻時不算。
     *
     * @param  list<array<string, mixed>>  $reports
     */
    private function hasProblem(array $reports): bool
    {
        foreach ($reports as $report) {
            if ($report['error'] !== null
                || $report['renamed'] !== []
                || $report['removed'] !== []
                || $report['acf_missing'] !== []
                || $report['cfb_missing'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     */
    private function render(array $reports): void
    {
        $this->table(
            ['代付設定', '幣別', 'FPay 家數', '基準時間', '換了銀行', '代號消失', '新增', '代付設定查無', '查表查無'],
            array_map(static fn (array $report): array => [
                sprintf('%d %s', $report['acf_id'], $report['acf_name']),
                $report['currency'],
                $report['error'] !== null ? '查詢失敗' : $report['bank_count'],
                $report['baseline_at'] ?? ($report['baseline_created'] ? '本次建立基線' : '-'),
                count($report['renamed']),
                count($report['removed']),
                $report['added'],
                count($report['acf_missing']),
                count($report['cfb_missing']),
            ], $reports),
        );

        foreach ($reports as $report) {
            $this->renderOne($report);
        }

        if (!$this->hasProblem($reports)) {
            $this->newLine();
            $this->info('✅ 所有代付設定的銀行清單都與基準一致，我方在用的代號也都還在清單上');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderOne(array $report): void
    {
        $label = sprintf('代付設定 %d（%s）', $report['acf_id'], $report['currency']);

        if ($report['baseline_created']) {
            $this->newLine();
            $this->line(sprintf('　%s：第一次稽核，已建立比對基線 %d 家，本次不比對。', $label, $report['bank_count']));

            return;
        }

        if ($report['error'] !== null) {
            $this->newLine();
            $this->warn(sprintf('　⚠️ %s：%s', $label, $report['error']));

            return;
        }

        if ($report['renamed'] !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '　🔴 %s：%d 個代號在 FPay 已經是別家銀行了。照舊送出不會被退回，錢會匯到別家銀行：',
                $label,
                count($report['renamed']),
            ));
            $this->table(
                ['代號', '基準當時是', 'FPay 現在是'],
                array_map(static fn (array $row): array => [$row['code'], $row['was'], $row['now']], $report['renamed']),
            );
        }

        if ($report['removed'] !== []) {
            $this->newLine();
            $this->warn(sprintf('　🔴 %s：%d 個代號已從 FPay 清單消失（送出會被退回）：', $label, count($report['removed'])));
            $this->table(
                ['代號', '基準當時是'],
                array_map(static fn (array $row): array => [$row['code'], $row['was']], $report['removed']),
            );
        }

        if ($report['acf_missing'] !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '　🔴 %s：代付設定 available_banks 有 %d 個代號不在 FPay 清單上：%s',
                $label,
                count($report['acf_missing']),
                implode('、', $report['acf_missing']),
            ));
        }

        if ($report['cfb_missing'] !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '　🔴 %s：cash_flow_banks 啟用中有 %d 個代號不在 FPay 清單上：%s',
                $label,
                count($report['cfb_missing']),
                implode('、', $report['cfb_missing']),
            ));
        }

        if ($report['baseline_accepted']) {
            $this->line(sprintf('　%s：已接受新基準，下次以本次清單為比對對象。', $label));
        }

        if (($report['renamed'] !== [] || $report['removed'] !== []) && !$report['baseline_accepted']) {
            $this->line(sprintf(
                '　%s：基準未更新，明天會再報一次。確認處理完畢後跑 --accept 接受新基準。',
                $label,
            ));
        }
    }

    /**
     * 有問題才發告警；一切正常時保持靜默，免得每日噪音把真正的告警淹掉。
     *
     * @param  list<array<string, mixed>>  $reports
     */
    private function notify(array $reports): void
    {
        $lines = [];
        $context = [];

        foreach ($reports as $report) {
            $label = sprintf('代付設定 %d（%s %s）', $report['acf_id'], $report['acf_name'], $report['currency']);

            foreach ($report['renamed'] as $row) {
                $lines[] = sprintf(
                    '  🔴 %s 代號 %s 的銀行從「%s」變成「%s」——照舊送出錢會匯到別家銀行',
                    $label,
                    $row['code'],
                    $row['was'],
                    $row['now'],
                );
            }

            if ($report['removed'] !== []) {
                $lines[] = sprintf(
                    '  🔴 %s 有 %d 個代號從 FPay 清單消失：%s',
                    $label,
                    count($report['removed']),
                    implode('、', array_column($report['removed'], 'code')),
                );
            }

            if ($report['acf_missing'] !== []) {
                $lines[] = sprintf(
                    '  🔴 %s 代付設定在用的代號有 %d 個不在 FPay 清單上：%s',
                    $label,
                    count($report['acf_missing']),
                    implode('、', $report['acf_missing']),
                );
            }

            if ($report['cfb_missing'] !== []) {
                $lines[] = sprintf(
                    '  🔴 %s cash_flow_banks 在用的代號有 %d 個不在 FPay 清單上：%s',
                    $label,
                    count($report['cfb_missing']),
                    implode('、', $report['cfb_missing']),
                );
            }

            if ($report['error'] !== null) {
                $lines[] = sprintf('  ⚠️ %s %s', $label, $report['error']);
            }

            $context[] = [
                'agent_cash_flow_id' => $report['acf_id'],
                'currency' => $report['currency'],
                'renamed' => count($report['renamed']),
                'removed' => count($report['removed']),
                'acf_missing' => count($report['acf_missing']),
                'cfb_missing' => count($report['cfb_missing']),
                'error' => $report['error'],
            ];
        }

        if ($lines === []) {
            return;
        }

        BotNotify::send(
            sprintf(
                "⚠️ FPay 代付銀行清單稽核發現異常\n%s\n\n查現行清單：php artisan cash-flow:third-party-query <代付設定 id> --raw\n確認處理完畢後接受新基準：php artisan cash-flow:audit-fpay-bank-list --accept",
                implode(PHP_EOL, $lines),
            ),
            [
                'connection' => (string) config('database.default'),
                'reports' => $context,
            ],
        );
    }
}
