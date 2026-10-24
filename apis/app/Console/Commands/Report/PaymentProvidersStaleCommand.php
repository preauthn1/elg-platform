<?php

declare(strict_types=1);

namespace App\Console\Commands\Report;

use App\Services\Report\PaymentCallbackAnalysisCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PaymentProvidersStaleCommand extends Command
{
    protected $signature = 'report:payment-providers:stale
        {--analysis= : 分析 JSON 路徑（預設 docs/reports/data/payment-callback-analysis.json）}
        {--json : 只輸出需重跑的 provider 逗號清單（供 workflow args 用）}
        {--all : 搭配 --json，輸出 config/payment.php 全部 provider（全量重跑用）}';

    protected $description = '比對原始碼雜湊，列出 payment-callback-analysis.json 需重新分析（變動/新增）與已下架的金流商';

    public function handle(PaymentCallbackAnalysisCache $cache): int
    {
        $analysisPath = $this->option('analysis') ?: base_path('docs/reports/data/payment-callback-analysis.json');

        $json = File::exists($analysisPath)
            ? (json_decode(File::get($analysisPath), true) ?: [])
            : [];

        $configNames = $cache->configProviderNames();
        $diff = $cache->diff($configNames, $json);

        if ($this->option('json')) {
            $this->line(implode(',', $this->option('all') ? $configNames : $diff['stale']));

            return self::SUCCESS;
        }

        if ($diff['globalChanged']) {
            $this->warn('⚠️ globalHash 變動（共用 trait 或 PROMPT_VERSION 改了）→ 全體需重跑');
        }

        if ($diff['stale'] === [] && $diff['removed'] === []) {
            $this->info('✓ 無變動，可直接 php artisan report:payment-providers（0 agent）');

            return self::SUCCESS;
        }

        if ($diff['stale'] !== []) {
            $this->line(sprintf('需重跑 %d 家：%s', count($diff['stale']), implode('、', $diff['stale'])));
        }
        if ($diff['removed'] !== []) {
            $this->line(sprintf('已下架 %d 家（merge 時將移除）：%s', count($diff['removed']), implode('、', $diff['removed'])));
        }

        $this->newLine();
        $this->line('下一步：Workflow(payment-callback-analysis, args=stale) → :merge --input=<fresh.json> → report:payment-providers');

        return self::SUCCESS;
    }
}
