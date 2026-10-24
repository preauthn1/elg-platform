<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// 檢查錢包冷卻
Schedule::command('schedule:check_wallet')->everyMinute();
// 檢查代收訂單是否已交易
Schedule::command('schedule:check_deposit_order')->everyMinute();
// 訂單 callback
Schedule::command('schedule:callback_order')->everyMinute();
// 執行代付訂單轉帳
Schedule::command('schedule:call_withdraw_order')->everyMinute();
// 檢查代付訂單是否已交易
Schedule::command('schedule:check_withdraw_order')->everyMinute();
