<?php

namespace App\Enums;

enum RegistrationStatusEnum: string
{
    case START = 'register_start';
    case DONE = 'register_done';
    case WAIT_PHONE_NUMBER = 'register_wait_phone_number';
    case WAIT_WHETHER_ENTER_AGENT_CODE = 'register_wait_whether_enter_agent_code';
    case WAIT_AGENT_INVITATION_CODE = 'register_wait_agent_invitation_code';
}
