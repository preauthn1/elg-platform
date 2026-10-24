<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ValidationCode;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\Frontend\DetectLanguage;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 工單 4540：忘記密碼限流必須排在 DecryptRequest 與 DetectLanguage 之後。
 *
 * 限流的 key 讀的是解密後的 username／手機／信箱；DecryptRequest 之前 request 只有
 * params 與 key 兩個欄位，key 會全部落成同一把、變成全站共用額度。
 * 限流擋下時回的 2037 訊息要跟著請求語系，所以 DetectLanguage 也要先跑。
 * 一般 feature 測試都用 withoutMiddleware(DecryptRequest::class) 略過解密，抓不到順序錯誤，
 * 所以這裡直接斷言框架依 priority 排序後的實際 middleware 順序。
 *
 * php artisan test tests/Feature/Frontend/ValidationCode/GuestPasswordResetThrottleOrderTest.php
 */
class GuestPasswordResetThrottleOrderTest extends TestCase
{
    #[DataProvider('throttledRouteProvider')]
    public function test_decrypt_and_language_run_before_throttle(string $uri, string $limiterName): void
    {
        $router = $this->app->make(Router::class);
        $route = $router->getRoutes()->match(Request::create($uri, 'POST'));

        $middlewares = array_values(array_map(
            static fn ($middleware): string => is_string($middleware) ? $middleware : $middleware::class,
            $router->gatherRouteMiddleware($route),
        ));

        $decryptIndex = array_search(DecryptRequest::class, $middlewares, true);
        $languageIndex = array_search(DetectLanguage::class, $middlewares, true);
        $throttleIndex = array_search('Illuminate\Routing\Middleware\ThrottleRequests:' . $limiterName, $middlewares, true);
        $order = implode(' > ', $middlewares);

        $this->assertIsInt($decryptIndex, 'DecryptRequest is missing on ' . $uri);
        $this->assertIsInt($languageIndex, 'DetectLanguage is missing on ' . $uri);
        $this->assertIsInt($throttleIndex, 'throttle:' . $limiterName . ' is missing on ' . $uri . ': ' . $order);
        $this->assertLessThan($languageIndex, $decryptIndex, 'DecryptRequest must run before DetectLanguage on ' . $uri . ': ' . $order);
        $this->assertLessThan($throttleIndex, $languageIndex, 'DetectLanguage must run before throttle on ' . $uri . ': ' . $order);
    }

    public static function throttledRouteProvider(): array
    {
        return [
            '檢核驗證碼' => ['/frontend/api/guest/check-validation-code', 'guest-member-accounts'],
            '取得會員帳號列表' => ['/frontend/api/guest/get-member-accounts', 'guest-member-accounts'],
            '重設密碼' => ['/frontend/api/guest/reset-password', 'guest-reset-password'],
        ];
    }
}
