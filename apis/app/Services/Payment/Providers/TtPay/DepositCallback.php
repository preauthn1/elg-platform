<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TtPay;

use App\Services\Payment\Providers\DgPay\DepositCallback as DgPayDepositCallback;

/**
 * TTPay 代收回調＝DGPay 代收回調（換名白牌，驗簽與冪等規則相同）。
 */
class DepositCallback extends DgPayDepositCallback {}
