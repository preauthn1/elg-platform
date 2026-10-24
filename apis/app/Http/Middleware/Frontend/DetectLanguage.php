<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class DetectLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $languageId = $this->getLanguageId($request);

        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, $languageId);

        return $next($request);
    }

    /**
     * 查詢語系ID
     */
    protected function getLanguageId(Request $request)
    {
        $languageId = 1;

        // 未帶 Accept-Language 時預設英文：header() 回 null 會讓 explode() 在 PHP 8 直接拋
        // TypeError，請求還沒進 route 就 500。en 在各白標 languages 表皆為 id=1，與下方
        // $languageId 的 fallback 值一致，故補預設值不改變原有語系結果。
        $locale = $request->header('Accept-Language', 'en');
        $locale = explode(',', $locale)[0];
        $locale = strtolower(trim($locale));

        // 設定語系
        $this->setLocale($locale);

        // 取得所有語系並緩存
        $languages = cache()->remember('active_languages', CacheTtl::DEFAULT->value, function () {
            return Language::select(['id', 'code'])
                ->where('status', 1)
                ->get()
                ->pluck('id', 'code')
                ->mapWithKeys(fn ($id, $code) => [strtolower($code) => $id]);
        });

        if (isset($languages[$locale])) {
            $languageId = $languages[$locale];
        }

        return $languageId;
    }

    /**
     * 設定語系 resources/lang
     */
    protected function setLocale(string $locale): void
    {
        // 轉換為標準語系代碼
        $locale = $this->convertLocale($locale);

        if (App::getLocale() != $locale && $locale != '') {
            App::setLocale($locale);
        }
    }

    /**
     * 轉換為 Laravel-Lang 定義的標準, 方便用 lang:update 更新語系
     */
    protected function convertLocale(string $locale): string
    {
        return match ($locale) {
            'fr' => 'fr', // 法語
            'id-id' => 'id', // 印尼文
            'ko' => 'ko', // 韓語
            'ms-my' => 'ms', // 馬來語
            'my-mm' => 'my', // 緬甸語
            'ta-in' => 'ta', // 泰米爾語 (印度)
            'th-th' => 'th', // 泰語
            'tl-ph' => 'fil', // 菲律賓與
            'vi-vn' => 'vi', // 越南語
            'zh-tw' => 'zh_TW',
            'zh-cn' => 'zh_CN',
            default => 'en',
        };
    }
}
