<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TtPay;

use App\Services\Payment\Providers\DgPay\Deposit as DgPayDeposit;

/**
 * TTPay 代收＝DGPay 代收（換名白牌，規格相同）。
 */
class Deposit extends DgPayDeposit {}
