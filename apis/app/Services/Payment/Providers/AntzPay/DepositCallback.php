<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AntzPay;

use App\Services\Payment\Providers\Kira\DepositCallback as KiraDepositCallback;

/**
 * AntzPay 代收回調＝Kira 代收回調（Gateway v1 同源協定），沿用 Kira 實作；
 * getDriverName() 依目錄名解析，log 與錯誤訊息自動標示 AntzPay。
 */
class DepositCallback extends KiraDepositCallback {}
