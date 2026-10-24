<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Ap8Pay;

/**
 * Ap8Pay 三支類別（Deposit／QueryOrder／DepositCallback）的共用輔助。
 *
 * 往返紀錄（`logExchange()`／`httpStatusOf()`）已收斂到共用的
 * {@see \App\Services\Payment\Traits\RecordsThirdPartyExchangeTrait}，由 AbstractMethod 提供給所有 provider，
 * 本 trait 不再自己持有一份。保留這個 trait 是因為 Ap8Pay 之後仍可能有自己的共用邏輯。
 */
trait Ap8PayTrait {}
