<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TtPay;

use App\Services\Payment\Providers\DgPay\Withdrawal as DgPayWithdrawal;

/**
 * TTPay 代付＝DGPay 代付（換名白牌，規格相同）；僅交易號前綴改為 TT 以利對帳辨識。
 */
class Withdrawal extends DgPayWithdrawal
{
    protected string $orderPrefix = 'TT';
}
