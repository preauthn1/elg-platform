<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Ap8Pay;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\OrderRequestLog;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use App\Services\Payment\Providers\Ap8Pay\QueryOrder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ap8Pay 代收查單測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Ap8Pay/QueryOrderTest.php
 *
 * 出站請求一律 Http::fake()，本檔不發出任何真實 HTTP 請求。
 */
class QueryOrderTest extends TestCase
{
    private QueryOrder $queryOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $info = [
            'HeaderApiKey' => 'test_header_api_key',
            'MerchantApiKey' => 'test_merchant_api_key',
            'APIUrl' => 'https://example.invalid/',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $this->queryOrder = new QueryOrder($agentCashFlow);
    }

    public function test_fetch_maps_success_and_normalizes_amount()
    {
        // Arrange
        Http::fake(['*' => Http::response([
            'data' => [
                'uniqueCode' => 'ABDG7TRZ3D4',
                'taskCode' => 'xdcXy6FML',
                'amount' => 30.00000001,
                'status' => 'SUCCESS',
            ],
        ])]);

        // Act
        $result = $this->queryOrder->fetch('xdcXy6FML');

        // Assert
        $this->assertSame('deposit', $result->type);
        $this->assertSame('ABDG7TRZ3D4', $result->orderNo);
        $this->assertSame(ProviderOrderStatusEnum::SUCCESS, $result->orderStatus);
        $this->assertSame('30.0000', $result->amount);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.invalid/merchants/deposits/xdcXy6FML'
            && $request->method() === 'GET'
            && $request->header('api-key') === ['test_header_api_key']);
    }

    public function test_fetch_maps_provider_statuses()
    {
        $map = [
            'PENDING' => ProviderOrderStatusEnum::PENDING,
            'PROCESSING' => ProviderOrderStatusEnum::PROCESSING,
            'FAILED' => ProviderOrderStatusEnum::FAIL,
            'WHATEVER' => ProviderOrderStatusEnum::ERROR,
        ];

        $sequence = Http::fakeSequence();
        foreach (array_keys($map) as $providerStatus) {
            $sequence->push(['data' => ['uniqueCode' => 'A1', 'status' => $providerStatus]]);
        }

        foreach ($map as $providerStatus => $expected) {
            $this->assertSame($expected, $this->queryOrder->fetch('task')->orderStatus, $providerStatus);
        }
    }

    public function test_fetch_without_amount_returns_null_amount()
    {
        // Arrange
        Http::fake(['*' => Http::response([
            'data' => ['uniqueCode' => 'A1', 'status' => 'PENDING'],
        ])]);

        // Act & Assert
        $this->assertNull($this->queryOrder->fetch('task')->amount);
    }

    public function test_empty_task_code_throws()
    {
        $this->expectException(ApiException::class);

        $this->queryOrder->fetch('');
    }

    public function test_invalid_envelope_throws()
    {
        // Arrange
        Http::fake(['*' => Http::response(['error' => ['type' => 'NotFound']], 200)]);

        // Assert & Act
        $this->expectException(ApiException::class);

        $this->queryOrder->fetch('task');
    }

    /**
     * 查單失敗要留紀錄，result 取三方的 error.type 而不是 message
     * （message 是會變的自由文字，原文已經在 response 欄位）
     */
    public function test_query_failure_records_provider_error_type()
    {
        // Arrange：舊單查單的實際回應
        Http::fake(['*' => Http::response(['error' => ['type' => 'NotFound', 'message' => 'Deposit not found.']], 404)]);

        // Act
        try {
            $this->queryOrder->fetch('7xQW5QsBH');
        } catch (\Throwable) {
            // 例外照舊往外拋，這裡只驗紀錄
        }

        // Assert
        $log = OrderRequestLog::where('third_party_order_no', '7xQW5QsBH')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('deposit_query', $log->action);
        $this->assertSame('failed:NotFound', $log->result);
        $this->assertSame(404, (int) $log->http_status);
        $this->assertStringContainsString('Deposit not found.', (string) $log->response_body);
    }

    public function test_http_error_throws()
    {
        // Arrange
        Http::fake(['*' => Http::response('INTERNAL SERVER ERROR', 500)]);

        // Assert & Act
        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->queryOrder->fetch('task');
    }
}
