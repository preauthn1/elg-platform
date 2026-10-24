<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Test\Payment;

use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * test:LegendPay 的連線設定與憑證從 --acf 指定的商戶設定讀取；讀取失敗時非 0 結束且不印出憑證值。
 */
class TestLegendPayCommandTest extends TestCase
{
    private const int CF_LEGEND = 99881;

    private const int CF_OTHER = 99882;

    private const string FAKE_URL = 'https://legendpay.test.invalid';

    private const string FAKE_TOKEN = [REDACTED]';

    private const string FAKE_SECRET = [REDACTED]';

    private function seedAgentCashFlow(int $cashFlowId, string $thirdPartyCode): AgentCashFlow
    {
        CashFlow::create([
            'id' => $cashFlowId, 'third_party_code' => $thirdPartyCode, 'name' => 'Sentinel',
            'type' => 3, 'status' => 1, 'info' => '', 'admin' => 0,
        ]);

        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => '(MYR) Sentinel', 'status' => 1, 'admin' => 0,
            'info' => ['API_URL' => self::FAKE_URL, 'API_TOKEN' => self::FAKE_TOKEN, 'API_SECRET' => self::FAKE_SECRET],
        ]);
    }

    public function test_rejects_non_legendpay_merchant_without_printing_credentials(): void
    {
        Http::fake();
        $acf = $this->seedAgentCashFlow(self::CF_OTHER, 'W_AKPAY');

        $exitCode = Artisan::call('test:LegendPay', ['--acf' => $acf->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('不是 LegendPay 商戶設定', $output);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $output);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
        Http::assertNothingSent();
    }

    public function test_reads_url_and_credentials_from_agent_cash_flow(): void
    {
        Http::fake(['*' => Http::response(['code' => 0])]);
        $acf = $this->seedAgentCashFlow(self::CF_LEGEND, 'W_LEGEND');

        $exitCode = Artisan::call('test:LegendPay', ['--acf' => $acf->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $output);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::FAKE_URL . '/LegendPay/payout'
            && $request->header('l-token') === [self::FAKE_TOKEN]);
    }
}
