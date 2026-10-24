<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\General\ContextKeyEnum;
use App\Http\Middleware\Frontend\DetectLanguage;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * Frontend DetectLanguage 對 Accept-Language 缺漏的處理。
 *
 * php artisan test tests/Feature/Middleware/DetectLanguageDefaultLocaleTest.php
 */
class DetectLanguageDefaultLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 語系清單有快取，避免跨測試污染
        Cache::forget('active_languages');
    }

    private function handleRequest(array $headers = []): void
    {
        $request = Request::create('http://' . $this->domain . '/frontend/api/guest/login', 'POST');

        // Request::create() 會塞 Symfony 預設的 HTTP_ACCEPT_LANGUAGE，必須先移除才能重現「完全沒帶」
        $request->headers->remove('Accept-Language');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        (new DetectLanguage)->handle($request, fn () => response('ok'));
    }

    /**
     * 未帶 Accept-Language 的請求不得炸掉。
     *
     * header() 回 null 時 explode() 會拋 TypeError，讓請求在 middleware 階段就 500，
     * 而且因為還沒進 route，log 也沒有 profile 可追（實測 Yes2Win 每小時數十筆）。
     */
    public function test_missing_accept_language_header_falls_back_to_english(): void
    {
        $this->handleRequest();

        $this->assertSame(1, Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value));
    }

    /**
     * 空字串 Accept-Language 同樣走預設，不得炸掉。
     */
    public function test_empty_accept_language_header_falls_back_to_english(): void
    {
        $this->handleRequest(['Accept-Language' => '']);

        $this->assertSame(1, Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value));
    }

    /**
     * 有帶 Accept-Language 時仍照原邏輯取第一個語系並對到 languages.id。
     */
    public function test_accept_language_header_still_resolves_language_id(): void
    {
        $language = Language::create([
            'name' => 'Traditional Chinese',
            'code' => 'zh-TW',
            'status' => 1,
        ]);

        $this->handleRequest(['Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8']);

        $this->assertSame($language->id, Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value));
    }
}
