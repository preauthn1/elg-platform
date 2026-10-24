<?php

namespace target\support\Enums;

class CashFlowEnum
{
    //TYPE
    const TYPE_DEPOSIT = 1;
    const TYPE_WITHDRAW = 3;
    const TYPE_SWAP = 5;
    const TYPE_DEFI_DEPOSIT = 6;
    const TYPE_DEFI_WITHDRAW = 7;

    //VENDOR
    const VENDOR_DEFI = 102;

    const DEPOSIT_DEFI_ENGINE = 101;
    const WITHDRAW_DEFI_ENGINE = 102;
}
