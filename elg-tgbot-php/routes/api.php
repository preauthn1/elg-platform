<?php

use App\Helpers\AESHelper;
use App\Helpers\CacheKeysHelper;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\TgBotController;
use App\Http\Middleware\RequestLogger;
use App\Http\Middleware\TgApiWebhookWhiteListCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

$middlewares = [
    RequestLogger::class,
];

Route::view('telegram/initdata', 'telegram-initdata');

Route::post('TGBot/{botId}', [TgBotController::class, 'webhook'])
    ->middleware($middlewares)
    ->middleware(TgApiWebhookWhiteListCheck::class)
    ->name('tgbot.webhook');

Route::get('whitelist', function () {
    $whiteListString = Cache::get(CacheKeysHelper::getTgApiWhiteListKey());
    print_r(str_replace("\n", '<br>', $whiteListString));
});

Route::post('platform/broadcast', [PlatformController::class, 'broadcast'])
    ->middleware($middlewares)
    ->name('platform.broadcast');

Route::post('aes/encrypt', function (Request $request) {
    $API_AES_KEY = config('platform_api.aes_key');
    $API_IV = config('platform_api.iv');
    $API_MD5_KEY = config('platform_api.md5_key');

    $aes = new AESHelper($API_AES_KEY, $API_IV);
    $encryptParams = $aes->encrypt($request->getContent());
    $key = md5($encryptParams . $API_MD5_KEY);

    return response()->json([
        'key' => $key,
        'params' => $encryptParams,
    ]);
});
