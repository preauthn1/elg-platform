<?php

namespace App\Enums;

enum ReplyMessageStatusEnum: string
{
    case REGISTER_WAIT_PHONE_NUMBER = 'register_wait_phone_number';
    case REGISTER_WAIT_WHETHER_ENTER_AGENT_CODE = 'register_wait_whether_enter_agent_code';
    case REGISTER_WAIT_AGENT_INVITATION_CODE = 'register_wait_agent_invitation_code';

    case DEPOSIT_WAIT_DEPOSIT_AMOUNT = 'deposit_wait_deposit_amount';
}
