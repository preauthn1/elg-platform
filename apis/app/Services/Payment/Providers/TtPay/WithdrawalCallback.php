<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TtPay;

use App\Services\Payment\Providers\DgPay\WithdrawalCallback as DgPayWithdrawalCallback;

/**
 * TTPay 代付回調＝DGPay 代付回調（換名白牌，驗簽與應答格式相同）。
 */
class WithdrawalCallback extends DgPayWithdrawalCallback {}
