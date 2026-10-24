<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors;

use App\Vendors\Contracts\HasCallbackPath;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 無縫錢包 Driver 預設回調路徑宣告守門測試 target/tickets#4400
 *
 * 後台「廠商管理」用 HasCallbackPath::defaultCallbackPath() 組回調網址。
 * make:vendor 產生的單錢包 Driver 會帶 `/external/api/sw/TODO` 佔位值，
 * 本測試擋兩件事：
 * 1. 宣告值格式合法（與 UpdateCallbackPathRequest 同一條規則）且沒有殘留 TODO。
 * 2. 宣告值確實對得上 routes/external/sw.php 註冊的路由前綴。
 *
 * php artisan test tests/Unit/Vendors/DriverCallbackPathDeclarationTest.php
 */
class DriverCallbackPathDeclarationTest extends TestCase
{
    /** 與 App\Http\Requests\Agent\VendorManagement\UpdateCallbackPathRequest 的 callback_path regex 一致 */
    private const string CALLBACK_PATH_PATTERN = '/^(\/[A-Za-z0-9_-]+)+$/';

    #[TestDox('每個 HasCallbackPath Driver 宣告的預設回調路徑格式合法且不含 TODO')]
    public function test_declared_callback_paths_are_well_formed(): void
    {
        $violations = [];

        foreach ($this->declaredCallbackPaths() as $class => $path) {
            if (preg_match(self::CALLBACK_PATH_PATTERN, $path) !== 1) {
                $violations[] = "{$class} => {$path}（格式不符）";

                continue;
            }

            if (str_contains($path, 'TODO')) {
                $violations[] = "{$class} => {$path}（仍是 make:vendor 的 TODO 佔位值）";
            }
        }

        $this->assertSame([], $violations, "以下 Driver 的 defaultCallbackPath() 不合格：\n" . implode("\n", $violations));
    }

    #[TestDox('每個 HasCallbackPath Driver 宣告的預設回調路徑都對得上已註冊的路由前綴')]
    public function test_declared_callback_paths_match_registered_routes(): void
    {
        $uris = array_map(
            static fn (RoutingRoute $route): string => $route->uri(),
            Route::getRoutes()->getRoutes(),
        );

        $violations = [];

        foreach ($this->declaredCallbackPaths() as $class => $path) {
            $prefix = ltrim($path, '/');

            if (!$this->hasRouteWithPrefix($uris, $prefix)) {
                $violations[] = "{$class} => {$path}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "以下 Driver 的 defaultCallbackPath() 找不到對應路由（routes/external/sw.php）：\n" . implode("\n", $violations),
        );
    }

    /**
     * 蒐集 config('vendor.driver') 中實作 HasCallbackPath 的 Driver 與其宣告值（同一 Driver 掛多個 vendor id 只算一次）
     *
     * @return array<class-string, string>
     */
    private function declaredCallbackPaths(): array
    {
        $paths = [];

        foreach (array_unique(array_values(config('vendor.driver'))) as $class) {
            if (!is_a($class, HasCallbackPath::class, true)) {
                continue;
            }

            $paths[$class] = $class::defaultCallbackPath();
        }

        $this->assertNotEmpty($paths, 'config(vendor.driver) 中沒有任何實作 HasCallbackPath 的 Driver，守門測試失去意義');

        return $paths;
    }

    /**
     * 路由 uri 等於前綴，或以「前綴 + /」開頭才算命中，避免 sw/pp 誤命中 sw/pps…
     *
     * @param  list<string>  $uris
     */
    private function hasRouteWithPrefix(array $uris, string $prefix): bool
    {
        foreach ($uris as $uri) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
