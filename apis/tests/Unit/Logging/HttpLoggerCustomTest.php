<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\HttpLoggerCustom;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Throwable;

/**
 * php artisan test tests/Unit/Logging/HttpLoggerCustomTest.php
 */
class HttpLoggerCustomTest extends TestCase
{
    private function requestToClosedPort(bool $verbose): void
    {
        try {
            Http::logAction('test_action', verbose: $verbose)
                ->timeout(3)
                ->get('http://0.0.0.0:1');
        } catch (Throwable) {
            // 預期失敗
        }
    }

    public function test_verbose_disabled_does_not_include_verbose_in_handler_stats(): void
    {
        Log::spy();

        $this->requestToClosedPort(verbose: false);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $stats = $context['handler_stats'] ?? null;

                return $stats !== null && !array_key_exists('verbose', $stats);
            });
    }

    public function test_verbose_enabled_includes_verbose_output_in_handler_stats_on_failure(): void
    {
        Log::spy();

        $this->requestToClosedPort(verbose: true);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $stats = $context['handler_stats'] ?? null;

                return $stats !== null
                    && array_key_exists('verbose', $stats)
                    && $stats['verbose'] !== '';
            });
    }

    /**
     * maskSensitive：命中的鍵以 '***' 取代、巢狀遞迴、非命中鍵保留原值；未給 maskKeys 則原樣回傳。
     */
    public function test_mask_sensitive_masks_matching_keys_recursively(): void
    {
        $masked = HttpLoggerCustom::maskSensitive([
            'pin' => '1234567890',
            'amount' => '5.00',
            'nested' => ['payment_key' => 'secret', 'telco' => 'maxis'],
        ], ['pin', 'payment_key']);

        $this->assertSame('***', $masked['pin'], '命中鍵應遮罩');
        $this->assertSame('5.00', $masked['amount'], '非命中鍵保留原值');
        $this->assertSame('***', $masked['nested']['payment_key'], '巢狀命中鍵應遞迴遮罩');
        $this->assertSame('maxis', $masked['nested']['telco'], '巢狀非命中鍵保留原值');

        $this->assertSame(
            ['pin' => '1234567890'],
            HttpLoggerCustom::maskSensitive(['pin' => '1234567890'], []),
            '未給 maskKeys 時原樣回傳（向後相容）'
        );

        $this->assertSame(
            '512*****8901',
            HttpLoggerCustom::maskSensitive(['bankaccno' => '512345678901'], ['bankaccno'])['bankaccno'],
            '鍵存在於 MaskTrait 規則表時依部分遮罩 profile 呈現'
        );
    }

    /**
     * logAction 帶 maskKeys 時，寫入 log 的 request.params 須遮罩支付憑證（不影響實際送出 body）。
     * 連線失敗仍會記錄 request context，故以 closed port 觸發。
     */
    public function test_log_action_masks_sensitive_request_params(): void
    {
        Log::spy();

        try {
            Http::logAction('test_action', maskKeys: ['pin', 'payment_key'])
                ->timeout(3)
                ->post('http://0.0.0.0:1', ['pin' => '999888', 'payment_key' => 'secret', 'amount' => '5.00']);
        } catch (Throwable) {
            // 預期連線失敗；request context 仍會被記錄
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $params = $context['request']['params'] ?? [];

                return ($params['pin'] ?? null) === '***'
                    && ($params['payment_key'] ?? null) === '***'
                    && ($params['amount'] ?? null) === '5.00';
            });
    }

    /**
     * 端到端：走 logAction 發出帶 Bearer token 的請求時，log 內不得出現憑證明文。
     * （maskHeaders 的單元案例在 CommonStringAndMaskTest，跟著 Common::maskHeaders 走。）
     * 連線失敗仍會記錄 request context，故以 closed port 觸發。
     */
    public function test_log_action_masks_authorization_header(): void
    {
        Log::spy();

        try {
            Http::logAction('test_action')
                ->withToken('super-secret-token')
                ->timeout(3)
                ->post('http://0.0.0.0:1', ['amount' => '5.00']);
        } catch (Throwable) {
            // 預期連線失敗；request context 仍會被記錄
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $headers = $context['request']['header'] ?? [];
                $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? null;

                return $authorization === 'Bearer **************oken'
                    && !str_contains(json_encode($context) ?: '', 'super-secret-token');
            });
    }
}
