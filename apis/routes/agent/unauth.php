<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});

// agent/api/login 已於 target/tickets#4548 停用（二次驗證可被繞過、無呼叫端），完整改寫待代理登入搬遷。
