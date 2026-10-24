<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\ApiException;
use Tests\TestCase;

class ApiExceptionTest extends TestCase
{
    public function test_render_falls_back_to_400_when_code_exceeds_http_range(): void
    {
        $exception = new ApiException('UUSlot GameLogin failed: Status=900500 Internal Server Error', 900500);

        $response = $exception->render();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(900500, $response->getData(true)['code']);
    }

    public function test_render_falls_back_to_400_when_code_is_zero(): void
    {
        $exception = new ApiException('something went wrong');

        $response = $exception->render();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_render_uses_legitimate_http_code_as_is(): void
    {
        foreach ([400, 401, 403, 404, 422, 423, 500, 503] as $code) {
            $response = (new ApiException('msg', $code))->render();
            $this->assertSame($code, $response->getStatusCode(), "code={$code}");
        }
    }

    public function test_render_falls_back_to_400_for_below_100_code(): void
    {
        $response = (new ApiException('msg', 99))->render();

        $this->assertSame(400, $response->getStatusCode());
    }
}
