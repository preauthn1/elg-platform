<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

/**
 * Common 雜項工具方法測試
 *
 * php artisan test tests/Unit/Helpers/Common/CommonUtilityTest.php
 */
class CommonUtilityTest extends TestCase
{
    public function test_flatten_headers(): void
    {
        $flat = Common::flattenHeaders([
            'X-A' => ['first', 'second'],
            'X-B' => 'plain',
            'X-C' => [],
        ]);
        $this->assertSame('first', $flat['X-A']);
        $this->assertSame('plain', $flat['X-B']);
        $this->assertNull($flat['X-C']);
    }

    public function test_format_trace_limits_length(): void
    {
        $e = new RuntimeException('x');
        $trace = $e->getTraceAsString();
        $limited = Common::formatTrace($e, limit: 20);
        $this->assertLessThanOrEqual(30, strlen($limited));
        $this->assertStringStartsWith(substr($trace, 0, 5), $limited);
    }

    public function test_get_guzzle_handler_context_from_connect_exception(): void
    {
        $request = new Request('GET', 'https://example.test/ping');
        $ex = new ConnectException(
            message: 'conn failed',
            request: $request,
            previous: null,
            handlerContext: [
                'errno' => 7,
                'error' => 'Could not connect',
                'total_time' => 1.234567,
            ],
        );
        $ctx = Common::getGuzzleHandlerContext($ex);
        $this->assertSame('https://example.test/ping', $ctx['url']);
        $this->assertSame(7, $ctx['errno']);
        $this->assertSame('Could not connect', $ctx['error']);
        $this->assertSame(1.235, $ctx['total_time']);
        $this->assertArrayNotHasKey('response_status', $ctx);
    }

    public function test_get_guzzle_handler_context_from_request_exception_with_response(): void
    {
        $request = new Request('POST', 'https://api.test/x');
        $response = new Response(502, [], str_repeat('a', 600));
        $ex = new RequestException(
            message: 'Bad response',
            request: $request,
            response: $response,
            previous: null,
            handlerContext: ['errno' => 0, 'error' => null, 'total_time' => 0.05],
        );
        $ctx = Common::getGuzzleHandlerContext($ex);
        $this->assertSame(502, $ctx['response_status']);
        $this->assertLessThanOrEqual(500, strlen((string) $ctx['response_body']));
    }

    public function test_get_guzzle_handler_context_unwraps_previous(): void
    {
        $request = new Request('GET', 'https://unwrap.test/');
        $inner = new ConnectException('inner', $request, null, ['errno' => 1, 'error' => 'e', 'total_time' => 0.1]);
        $outer = new RuntimeException('wrap', 0, $inner);
        $ctx = Common::getGuzzleHandlerContext($outer);
        $this->assertSame('https://unwrap.test/', $ctx['url']);
    }

    public function test_get_guzzle_handler_context_returns_empty_for_unrelated_throwable(): void
    {
        $this->assertSame([], Common::getGuzzleHandlerContext(new RuntimeException('plain')));
    }
}
