<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Olympus 後台固定使用繁體中文：Filament 內建文字（按鈕、搜尋、篩選、分頁、錯誤訊息）才會顯示中文。
 *
 * 只掛在 Olympus panel，不改 app 預設語系；會員端與代理端的語系由各自的 DetectLanguage 決定。
 * Olympus 沒有切換語言的功能，使用者都是看中文的維運人員。
 */
class SetOlympusLocale
{
    private const string LOCALE = 'zh_TW';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::LOCALE);

        return $next($request);
    }
}
