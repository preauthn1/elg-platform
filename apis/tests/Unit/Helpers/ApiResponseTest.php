<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\ApiResponse;
use App\Http\Resources\OnlyPaginateResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * ApiResponse 測試
 *
 * php artisan test tests/Unit/Helpers/ApiResponseTest.php
 */
class ApiResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget(ContextKeyEnum::REQUEST_ID->value);
        parent::tearDown();
    }

    public function test_success_includes_tid_and_default_message(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'req-abc');

        $response = ApiResponse::success(['foo' => 1]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(200, $payload['code']);
        $this->assertSame(['foo' => 1], $payload['data']);
        $this->assertSame('req-abc', $payload['tid']);
        $this->assertNotEmpty($payload['message']);
    }

    public function test_success_with_custom_message(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'x');

        $response = ApiResponse::success([], message: 'OK');
        $payload = $response->getData(true);
        $this->assertSame('OK', $payload['message']);
    }

    public function test_success_with_pagination_shape(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'p');

        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 15, currentPage: 1);
        $resource = new OnlyPaginateResource($paginator);
        $response = ApiResponse::successWithPagination(['a' => 1], $resource);

        $payload = $response->getData(true);
        $this->assertSame(['a' => 1], $payload['data']['items']);
        $this->assertArrayHasKey('pagination', $payload['data']);
        $this->assertSame('p', $payload['tid']);
    }

    public function test_error_default_code_and_message(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'e');

        $response = ApiResponse::error();
        $this->assertSame(400, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(400, $payload['code']);
        $this->assertSame('e', $payload['tid']);
    }

    public function test_error_custom_code(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'e2');

        $response = ApiResponse::error(message: 'Bad', code: 422);
        $this->assertSame(422, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(422, $payload['code']);
        $this->assertSame('Bad', $payload['message']);
    }

    public function test_custom_code_http_200_with_business_code(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'c');

        $response = ApiResponse::customCode(10001, message: 'done');
        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(10001, $payload['code']);
        $this->assertSame('done', $payload['message']);
        $this->assertSame('c', $payload['tid']);
    }
}
