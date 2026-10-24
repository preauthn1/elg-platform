<?php

namespace App\Enums;

enum PlatformApiCodeEnum: int
{
    case SUCCESS = 1000;

    case REGISTER_DUPLICATE_TG_USER_ID = 1002;
    case REGISTER_DUPLICATE_PHONE_NUMBER_WITH_AGENT_CODE = 10901;
}
