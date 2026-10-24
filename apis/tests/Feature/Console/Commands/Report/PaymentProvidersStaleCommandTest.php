<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Report;

use App\Services\Report\PaymentCallbackAnalysisCache;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/Report/PaymentProvidersStaleCommandTest.php
 *
 * 驗 --json 與 --all 的輸出清單：workflow payment-callback-analysis 直接把這行當 args。
 * 分析 JSON 以 --analysis 指向暫存檔，hash 由真實的 PaymentCallbackAnalysisCache 蓋章，
 * 不讀寫 docs/reports/data/ 底下的正式檔。
 */
class PaymentProvidersStaleCommandTest extends TestCase
{
    private string $analysisPath;

    private PaymentCallbackAnalysisCache $cache;

    /** @var array<int, string> */
    private array $configNames;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = app(PaymentCallbackAnalysisCache::class);
        $this->configNames = $this->cache->configProviderNames();
        $this->assertGreaterThanOrEqual(2, count($this->configNames), 'config/payment.php 至少要有兩家金流商，測試才分得出 stale 與全量');

        $this->analysisPath = (string) tempnam(sys_get_temp_dir(), 'payment-callback-analysis-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->analysisPath)) {
            unlink($this->analysisPath);
        }

        parent::tearDown();
    }

    public function test_json_lists_only_stale_providers(): void
    {
        $this->writeAnalysis([$this->configNames[0]]);

        $this->artisan('report:payment-providers:stale', ['--analysis' => $this->analysisPath, '--json' => true])
            ->expectsOutput(implode(',', array_slice($this->configNames, 1)))
            ->assertExitCode(0);
    }

    public function test_json_with_all_lists_every_config_provider_even_when_some_are_fresh(): void
    {
        $this->writeAnalysis([$this->configNames[0]]);

        $this->artisan('report:payment-providers:stale', ['--analysis' => $this->analysisPath, '--json' => true, '--all' => true])
            ->expectsOutput(implode(',', $this->configNames))
            ->assertExitCode(0);
    }

    public function test_json_with_all_lists_every_config_provider_when_all_are_fresh(): void
    {
        $this->writeAnalysis($this->configNames);

        $this->artisan('report:payment-providers:stale', ['--analysis' => $this->analysisPath, '--json' => true, '--all' => true])
            ->expectsOutput(implode(',', $this->configNames))
            ->assertExitCode(0);
    }

    public function test_json_without_all_outputs_empty_line_when_all_are_fresh(): void
    {
        $this->writeAnalysis($this->configNames);

        $this->artisan('report:payment-providers:stale', ['--analysis' => $this->analysisPath, '--json' => true])
            ->expectsOutput('')
            ->assertExitCode(0);
    }

    /**
     * 寫一份分析 JSON，只替指定的金流商蓋上目前的 sourceHash，其餘視為需重跑。
     *
     * @param  array<int, string>  $freshNames
     */
    private function writeAnalysis(array $freshNames): void
    {
        $providers = [];
        foreach ($freshNames as $name) {
            $providers[$name] = ['sourceHash' => $this->cache->providerHash($name)];
        }

        file_put_contents($this->analysisPath, (string) json_encode([
            'globalHash' => $this->cache->globalHash(),
            'providers' => $providers,
        ]));
    }
}
