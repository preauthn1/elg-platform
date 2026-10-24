<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\RequestLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RequestLogger 對 external 三方回調的憑證遮罩行為。
 *
 * php artisan test tests/Feature/Middleware/RequestLoggerCredentialMaskTest.php
 */
class RequestLoggerCredentialMaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['custom.log_request' => true]);
    }

    private function handleExternalRequest(array $params): void
    {
        $request = Request::create(
            'http://example.invalid/external/api/deposit/notify/58',
            'POST',
            $params,
        );

        (new RequestLogger)->handle($request, fn () => response('success'));
    }

    /**
     * external 回調的請求 log：PIN 必須套秘密類部分遮罩（16 碼只露尾 4），避免支付憑證明文外洩。
     */
    public function test_external_callback_request_log_masks_pin(): void
    {
        Log::spy();

        $this->handleExternalRequest([
            'pin' => '4830185194585221',
            'token' => '5c80133d59b13ad283bdc8747e1a2974',
            'amount' => '30.00',
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, '請求紀錄')
                    && ($context['request']['params']['pin'] ?? null) === '************5221';
            })
            ->atLeast()->once();
    }

    /**
     * external 回調的請求 log：token 必須原樣保留——它是 EpicPay/FPay/Sure/SurePay 的簽名欄位，
     * 遮掉會讓「從 log 重算簽名」失準。
     */
    public function test_external_callback_request_log_preserves_token_signature(): void
    {
        Log::spy();

        $this->handleExternalRequest([
            'pin' => '4830185194585221',
            'token' => '5c80133d59b13ad283bdc8747e1a2974',
            'amount' => '30.00',
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if (!str_contains($message, '請求紀錄')) {
                    return false;
                }
                $params = $context['request']['params'];

                return ($params['token'] ?? null) === '5c80133d59b13ad283bdc8747e1a2974'
                    && ($params['amount'] ?? null) === '30.00';
            })
            ->atLeast()->once();
    }
}
