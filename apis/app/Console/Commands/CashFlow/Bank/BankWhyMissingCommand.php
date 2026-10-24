<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankVisibilityChecker;
use Illuminate\Console\Command;

/**
 * 銀行可見性診斷命令
 *
 * 【分類】Bank — 唯讀診斷工具，長期保留。
 *
 * 三模式（依參數組合自動判斷）：
 * - 模式 A：--bank=N --agent=M               單一銀行為何不可見
 * - 模式 B：--agent=M --currency=X           agent + 幣別下所有不可見銀行
 * - 模式 C：--agent=M --cashflow=N           agent + cashflow 下所有 feed bank 可見性
 *
 * 對應前端路由：frontend/api/withdrawal/banks (WithdrawalController::getBanks)
 *
 * 🔴 「為什麼缺項」**一律以本 checklist 的斷點為結論，禁止另寫一次性 SQL 當答案**
 * ——手寫 SQL 只驗得到你想到的那一關，會漏掉鏈上其他關卡而給出相反的結論。
 * 完全 read-only，不寫入 DB。
 *
 * ## 退役條件
 *
 * **不設退役條件。** 銀行在前台可不可見是「四層鏈 ＋ ACF 設定 ＋ 幣別 ＋ 限額」的合成
 * 結果，只要那條鏈還在，就需要一個能回答「為什麼這家銀行不見了」的入口。
 */
class BankWhyMissingCommand extends Command
{
    protected $signature = 'bank:why-missing
                            {--bank= : 指定銀行 ID（與 --agent 並用）}
                            {--agent= : 指定代理 ID（必填）}
                            {--currency= : 指定幣別（與 --agent 並用）}
                            {--cashflow= : 指定金流商 ID（與 --agent 並用）}
                            {--json : JSON 輸出}';

    protected $description = '診斷銀行為何不出現在前端 withdrawal/banks 清單';

    public function __construct(protected BankVisibilityChecker $checker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $agentId = $this->option('agent');
        if (!$agentId) {
            $this->error('--agent 為必填');

            return self::FAILURE;
        }
        $agentId = (int) $agentId;

        $bankId = $this->option('bank');
        $currency = $this->option('currency');
        $cashflowId = $this->option('cashflow');

        $mode = match (true) {
            $bankId !== null => 'single',
            $currency !== null => 'agent_currency',
            $cashflowId !== null => 'agent_cashflow',
            default => null,
        };

        if ($mode === null) {
            $this->error('需提供 --bank / --currency / --cashflow 其中一個');

            return self::FAILURE;
        }

        $result = match ($mode) {
            'single' => [$this->checker->checkBankForAgent((int) $bankId, $agentId)],
            'agent_currency' => $this->checker->listInvisibleBanksForAgent($agentId, $currency),
            'agent_cashflow' => $this->checker->listAllBanksForCashflow($agentId, (int) $cashflowId),
        };

        if ($this->option('json')) {
            $this->renderJson($mode, $agentId, $result);
        } else {
            $this->renderText($mode, $agentId, $result);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    protected function renderText(string $mode, int $agentId, array $results): void
    {
        $this->info(str_repeat('=', 60));
        $this->info(sprintf('Agent %d  Mode: %s', $agentId, $mode));
        $this->info(str_repeat('=', 60));

        foreach ($results as $r) {
            $this->newLine();
            $this->info(sprintf(
                '[Bank id=%s "%s"]  visible=%s',
                $r['bank_id'] ?? '-',
                $r['bank_name'] ?? '-',
                ($r['visible'] ?? false) ? 'true' : 'false'
            ));

            foreach ($r['chains'] ?? [] as $chain) {
                $this->line(sprintf('  走 cf=%d 的檢查鏈：', $chain['cash_flow_id']));
                foreach ($chain['checks'] as $check) {
                    $mark = $check['passed'] ? '✓' : '✗';
                    $this->line(sprintf('    %s Step %d: %s', $mark, $check['step'], $check['name']));
                }
            }

            if (!($r['visible'] ?? false) && isset($r['suggestion'])) {
                $sg = $r['suggestion'];
                $this->newLine();
                $this->warn(sprintf('💡 %s', $sg['message']));
                if (!empty($sg['command'])) {
                    $this->line(sprintf('   建議命令：%s', $sg['command']));
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    protected function renderJson(string $mode, int $agentId, array $results): void
    {
        $this->line(json_encode([
            'generated_at' => now()->toIso8601String(),
            'mode' => $mode,
            'agent_id' => $agentId,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
