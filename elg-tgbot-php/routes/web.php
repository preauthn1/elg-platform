<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'msg' => 'Homepage',
        'env' => app()->environment(),
        'name' => 'TGBOT-PHP',
        'myIP' => App\Helpers\CommonHelper::getRealIp(),
        'debugInfo' => '',
        'debugInfo2' => null,
    ]);
});
