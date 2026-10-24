<?php

namespace App\Enums;

enum DepositStatusEnum: string
{
    case START = 'deposit_start';
    case DONE = 'deposit_done';
    case WAIT_ORDER_TYPE = 'deposit_wait_order_type';
    case WAIT_DEPOSIT_AMOUNT = 'deposit_wait_deposit_amount';

}
