<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PaymentProvidersReportCommandTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('framework/testing/payment-providers-' . uniqid() . '.html');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->output)) {
            File::delete($this->output);
        }
        parent::tearDown();
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/payment-callback-analysis.fixture.json');
    }

    public function test_renders_report_from_config_and_analysis_json(): void
    {
        $this->artisan('report:payment-providers', [
            '--skip-whitelabel' => true,
            '--analysis' => $this->fixture(),
            '--output' => $this->output,
        ])->assertExitCode(0);

        $this->assertTrue(File::exists($this->output));
        $html = File::get($this->output);

        // 版面/樣式來自 Blade（確定性）
        $this->assertStringContainsString('<title>APIs 金流商完整盤點報告</title>', $html);
        $this->assertStringContainsString('report:payment-providers', $html);

        // §1 config key 欄即時由 config/payment.php 計算（曾 regression 的欄位）
        $this->assertStringContainsString('EPICPAY', $html);
        $this->assertStringContainsString('YEEPAY', $html);

        // §3 回調資料來自 analysis JSON（用不含引號的 response，避免 Blade {{ }} 把單引號轉成 &#039; 而誤判）
        $this->assertStringContainsString('OK / FAIL', $html);

        // 風險不在本報告，只留指向風險發現報告的說明
        $this->assertStringNotContainsString('id="risks"', $html);
        $this->assertStringContainsString('金流商風險發現', $html);
    }

    public function test_skip_whitelabel_marks_section_unavailable(): void
    {
        $this->artisan('report:payment-providers', [
            '--skip-whitelabel' => true,
            '--analysis' => $this->fixture(),
            '--output' => $this->output,
        ])->assertExitCode(0);

        $this->assertStringContainsString('白標資料本次未取得', File::get($this->output));
    }

    public function test_fails_when_analysis_json_missing(): void
    {
        $this->artisan('report:payment-providers', [
            '--analysis' => base_path('tests/Fixtures/does-not-exist.json'),
            '--output' => $this->output,
        ])->assertExitCode(1);
    }
}
