<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AntzPay;

use App\Services\Payment\Providers\Kira\Deposit as KiraDeposit;

/**
 * AntzPay 代收＝Kira 代收（Gateway v1 同源協定），沿用 Kira 實作；
 * getDriverName() 依目錄名解析，log 與錯誤訊息自動標示 AntzPay。
 */
class Deposit extends KiraDeposit {}
