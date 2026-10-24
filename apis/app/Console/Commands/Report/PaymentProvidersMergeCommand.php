<?php

declare(strict_types=1);

namespace App\Console\Commands\Report;

use App\Services\Report\PaymentCallbackAnalysisCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PaymentProvidersMergeCommand extends Command
{
    protected $signature = 'report:payment-providers:merge
        {--analysis= : 分析 JSON 路徑（預設 docs/reports/data/payment-callback-analysis.json）}
        {--input= : workflow 產出的新分析 JSON（含 providers），套用至既有檔}
        {--reseed : 重新蓋章所有既有 entry 的 sourceHash + globalHash（初始化用，不需 --input）}';

    protected $description = '套用 workflow 新分析至 payment-callback-analysis.json，並蓋章 sourceHash / globalHash';

    public function handle(PaymentCallbackAnalysisCache $cache): int
    {
        $analysisPath = $this->option('analysis') ?: base_path('docs/reports/data/payment-callback-analysis.json');

        if (!$this->option('input') && !$this->option('reseed')) {
            $this->error('需指定 --input=<fresh.json> 或 --reseed');

            return self::FAILURE;
        }

        $json = File::exists($analysisPath)
            ? (json_decode(File::get($analysisPath), true) ?: [])
            : ['source' => 'workflow:payment-callback-analysis', 'providers' => []];

        $fresh = [];
        if ($this->option('input')) {
            $inputPath = $this->option('input');
            if (!File::exists($inputPath)) {
                $this->error("找不到 --input：{$inputPath}");

                return self::FAILURE;
            }
            $decoded = json_decode(File::get($inputPath), true);
            if (!is_array($decoded)) {
                $this->error('--input JSON 格式錯誤');

                return self::FAILURE;
            }
            // 接受 {providers:{...}} 或直接 provider map
            $fresh = $decoded['providers'] ?? $decoded;
            $json['generatedAt'] = now()->toDateString();
        }

        $configNames = $cache->configProviderNames();
        $merged = $cache->merge($json, $fresh, $configNames, (bool) $this->option('reseed'));

        File::ensureDirectoryExists(dirname($analysisPath));
        File::put($analysisPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info("已更新：{$analysisPath}");
        $this->line(sprintf(
            '套用新分析 %d 家｜%s｜共 %d 家｜globalHash %s',
            count($fresh),
            $this->option('reseed') ? '已重新蓋章全體 hash' : '僅蓋章新分析家',
            count($merged['providers']),
            substr((string) ($merged['globalHash'] ?? ''), 0, 12)
        ));

        return self::SUCCESS;
    }
}
