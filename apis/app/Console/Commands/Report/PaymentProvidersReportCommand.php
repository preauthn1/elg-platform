<?php

declare(strict_types=1);

namespace App\Console\Commands\Report;

use App\Enums\CashFlow\CashFlowScopeEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Throwable;

class PaymentProvidersReportCommand extends Command
{
    protected $signature = 'report:payment-providers
        {--output= : 輸出 HTML 路徑（預設 docs/reports/payment-providers-report.html）}
        {--analysis= : 回調分析 JSON 路徑（預設 docs/reports/data/payment-callback-analysis.json）}
        {--connections= : 白標連線（逗號分隔，預設 6 個正式白標）}
        {--skip-whitelabel : 跳過白標 DB 查詢（§2 留空）}';

    protected $description = '產生金流商完整盤點報告 HTML（清單、白標對照、文檔缺口即時計算，回調邏輯讀分析 JSON，Blade 渲染；風險見風險發現報告）';

    /** 正式白標連線（對應 config/database.php；WTT/Wintoto 併入 VWorld）。 */
    private const WHITE_LABELS = ['VWorld', 'TPower', 'VPlus33', 'Yes2Win', 'Alfawin77', 'Me77']; // hardcode-ok: 內部盤點報告的預設欄位，值是 config/database.php 的連線名，可用 --connections 覆蓋

    public function handle(): int
    {
        $analysisPath = $this->option('analysis') ?: base_path('docs/reports/data/payment-callback-analysis.json');
        $outputPath = $this->option('output') ?: base_path('docs/reports/payment-providers-report.html');

        if (!File::exists($analysisPath)) {
            $this->error("找不到分析 JSON：{$analysisPath}（請先跑 payment-callback-analysis workflow）");

            return self::FAILURE;
        }

        $analysis = json_decode(File::get($analysisPath), true);
        if (!is_array($analysis) || !isset($analysis['providers'])) {
            $this->error('分析 JSON 格式錯誤（缺 providers）');

            return self::FAILURE;
        }

        $analysisProviders = $analysis['providers'];
        $providers = $this->resolveProviders();

        $inventory = $this->buildInventory($providers, $analysisProviders);
        $callback = $this->buildCallback($providers, $analysisProviders);

        $docHaves = collect($inventory)->where('hasDoc', true)->pluck('name')->values()->all();
        $docMissing = collect($inventory)->where('hasDoc', false)->pluck('name')->values()->all();
        $depositOnly = collect($inventory)->where('withdrawal', false)->pluck('name')->values()->all();

        $connections = $this->option('connections')
            ? array_map('trim', explode(',', (string) $this->option('connections')))
            : self::WHITE_LABELS;

        $whitelabel = $this->option('skip-whitelabel')
            ? ['available' => false, 'columns' => $connections, 'rows' => [], 'observations' => [], 'errors' => ['已指定 --skip-whitelabel']]
            : $this->buildWhitelabel($providers, $connections);

        $stats = [
            'total' => count($inventory),
            'withdrawal' => count($inventory) - count($depositOnly),
            'depositOnly' => count($depositOnly),
            'docHave' => count($docHaves),
            'docMissing' => count($docMissing),
        ];

        $html = View::make('reports.payment-providers', [
            'generatedAt' => $analysis['generatedAt'] ?? now()->toDateString(),
            'stats' => $stats,
            'inventory' => $inventory,
            'whitelabel' => $whitelabel,
            'callback' => $callback,
            'docHaves' => $docHaves,
            'docMissing' => $docMissing,
            'depositOnly' => $depositOnly,
        ])->render();

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $html);

        $this->info("已寫出報告：{$outputPath}");
        $this->line(sprintf(
            '金流商 %d（代收+代付 %d / 代收限定 %d）｜文檔 有 %d / 缺 %d｜白標 %s',
            $stats['total'], $stats['withdrawal'], $stats['depositOnly'],
            $stats['docHave'], $stats['docMissing'],
            $whitelabel['available'] ? '已查' : '略過'
        ));

        return self::SUCCESS;
    }

    /**
     * 從 config/payment.php 解析 provider 清單。
     *
     * @return array<int, array{key: string, name: string, withdrawal: bool, hasDoc: bool}>
     */
    private function resolveProviders(): array
    {
        $out = [];
        foreach ((array) config('payment.provider', []) as $key => $class) {
            if (!preg_match('#Providers\\\\([^\\\\]+)\\\\Driver#', (string) $class, $m)) {
                continue;
            }
            $name = $m[1];
            $out[] = [
                'key' => (string) $key,
                'name' => $name,
                'withdrawal' => File::exists(app_path("Services/Payment/Providers/{$name}/WithdrawalCallback.php")),
                'hasDoc' => File::isDirectory(base_path("docs/integrations/payments/{$name}")),
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @param  array<int, array{key: string, name: string, withdrawal: bool, hasDoc: bool}>  $providers
     * @param  array<string, mixed>  $analysisProviders
     * @return array<int, array<string, mixed>>
     */
    private function buildInventory(array $providers, array $analysisProviders): array
    {
        $rows = [];
        foreach ($providers as $i => $p) {
            $note = $analysisProviders[$p['name']]['inventoryNote'] ?? '';
            $parts = array_filter([$p['withdrawal'] ? '' : '代收限定', $note]);
            $rows[] = [
                'n' => $i + 1,
                'name' => $p['name'],
                'key' => $p['key'],
                'deposit' => true,
                'withdrawal' => $p['withdrawal'],
                'hasDoc' => $p['hasDoc'],
                'note' => implode('；', $parts),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{key: string, name: string, withdrawal: bool, hasDoc: bool}>  $providers
     * @param  array<string, mixed>  $analysisProviders
     * @return array<int, array<string, string>>
     */
    private function buildCallback(array $providers, array $analysisProviders): array
    {
        $rows = [];
        foreach ($providers as $p) {
            $a = $analysisProviders[$p['name']] ?? [];
            $rows[] = [
                'name' => $p['name'],
                'response' => $a['response'] ?? '—',
                'depositSuccessTrigger' => $a['depositSuccessTrigger'] ?? '—',
                'withdrawalSuccessTrigger' => $a['withdrawalSuccessTrigger'] ?? '—',
                'statusStyle' => $a['statusStyle'] ?? '—',
            ];
        }

        return $rows;
    }

    /**
     * 跨白標查啟用矩陣（best-effort）。
     *
     * @param  array<int, array{key: string, name: string, withdrawal: bool, hasDoc: bool}>  $providers
     * @param  array<int, string>  $connections
     * @return array{available: bool, columns: array<int, string>, rows: array<int, mixed>, observations: array<int, string>, errors: array<int, string>}
     */
    private function buildWhitelabel(array $providers, array $connections): array
    {
        // cash_flows.third_party_code 形如 D_<configKey>/W_<configKey>（用 config key，非 dir 名）
        $nameByCode = [];
        foreach ($providers as $p) {
            $nameByCode[strtoupper($p['key'])] = $p['name'];
        }

        $matrix = [];
        $errors = [];
        $orphans = [];
        $available = false;

        foreach ($connections as $conn) {
            try {
                $rows = DB::connection($conn)->table('agent_cash_flows as acf')
                    ->join('cash_flows as cf', 'cf.id', '=', 'acf.cash_flow_id')
                    // 只統計由本專案處理的通道，判斷與 CashFlowScopeEnum::isHandledByApis() 相同；舊系統範疇的通道由 API_Agent 處理，不屬本報告
                    ->where(fn ($q) => $q->where('cf.id', '>', CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID)
                        ->orWhereIn('cf.id', CashFlowScopeEnum::APIS_TAKEOVER_CASH_FLOW_IDS))
                    ->get(['cf.third_party_code', 'cf.type', 'acf.status']);
                $available = true;
            } catch (Throwable $e) {
                $errors[] = "{$conn}: " . mb_substr($e->getMessage(), 0, 60);

                continue;
            }

            foreach ($rows as $r) {
                $code = (string) $r->third_party_code;
                $bare = strtoupper((string) preg_replace('/^[DW]_/i', '', $code));
                $name = $nameByCode[$bare] ?? null;
                if ($name === null) {
                    $orphans[$code] = true;

                    continue;
                }
                $dir = ((int) $r->type === 3) ? 'withdrawal' : 'deposit';
                $matrix[$name][$conn] ??= ['deposit' => 'none', 'withdrawal' => 'none'];
                if ((int) $r->status === 1) {
                    $matrix[$name][$conn][$dir] = 'on';
                } elseif ($matrix[$name][$conn][$dir] === 'none') {
                    $matrix[$name][$conn][$dir] = 'off';
                }
            }
        }

        $columns = array_values(array_intersect($connections, self::WHITE_LABELS));

        $rows = [];
        foreach (array_keys($matrix) as $name) {
            $cells = [];
            $appeared = false;
            foreach ($columns as $conn) {
                $cell = $matrix[$name][$conn] ?? ['deposit' => 'none', 'withdrawal' => 'none'];
                if ($cell['deposit'] !== 'none' || $cell['withdrawal'] !== 'none') {
                    $appeared = true;
                }
                $cells[] = $cell;
            }
            if ($appeared) {
                $rows[] = ['name' => $name, 'cells' => $cells];
            }
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'available' => $available,
            'columns' => $columns,
            'rows' => $rows,
            'observations' => $this->buildObservations($rows, $columns, $providers, array_keys($orphans)),
            'errors' => $errors,
        ];
    }

    /**
     * 由白標矩陣推導確定性觀察。
     *
     * @param  array<int, array{name: string, cells: array<int, array<string, string>>}>  $rows
     * @param  array<int, string>  $columns
     * @param  array<int, array{key: string, name: string, withdrawal: bool, hasDoc: bool}>  $providers
     * @param  array<int, string>  $orphanCodes
     * @return array<int, string>
     */
    private function buildObservations(array $rows, array $columns, array $providers, array $orphanCodes): array
    {
        $obs = [];
        if ($rows === []) {
            return $obs;
        }

        $coverage = [];
        $perConn = array_fill_keys($columns, 0);
        foreach ($rows as $row) {
            $cnt = 0;
            foreach ($row['cells'] as $i => $cell) {
                if ($cell['deposit'] === 'on' || $cell['withdrawal'] === 'on') {
                    $cnt++;
                    $perConn[$columns[$i]]++;
                }
            }
            $coverage[$row['name']] = $cnt;
        }

        arsort($coverage);
        $topName = array_key_first($coverage);
        if ($topName !== null) {
            $obs[] = sprintf('<b>%s</b> 覆蓋最廣（%d 個白標）。', $topName, $coverage[$topName]);
        }

        arsort($perConn);
        $busiest = array_key_first($perConn);
        $leanest = array_key_last($perConn);
        if ($busiest !== null && $leanest !== null) {
            $obs[] = sprintf('<b>%s</b> 接入最多金流商（%d 家）；<b>%s</b> 最精簡（%d 家）。', $busiest, $perConn[$busiest], $leanest, $perConn[$leanest]);
        }

        $appeared = array_column($rows, 'name');
        $never = array_values(array_diff(array_column($providers, 'name'), $appeared));
        if ($never !== []) {
            $obs[] = sprintf('沒有由 APIs 處理的正式站通道：<b>%s</b>。舊系統範疇的通道不在本表，這幾家仍可能經由舊系統在正式站使用。', implode('、', $never));
        }

        if ($orphanCodes !== []) {
            sort($orphanCodes);
            $obs[] = sprintf('⚠️ 出現無對應實作的 cash_flow code：<span class="mono">%s</span>，疑為遺留/測試髒資料，建議確認。', implode('、', $orphanCodes));
        }

        return $obs;
    }
}
