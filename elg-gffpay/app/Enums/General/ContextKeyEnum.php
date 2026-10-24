<?php

namespace App\Enums\General;

enum ContextKeyEnum: string
{
    case LOG_HTTP_ACTION = 'logHttpAction';
    case TOKEN = [REDACTED]';
    case MEMBER = 'MEMBER';
    case AGENT = 'AGENT';
    case USER_DATA = 'USER_DATA';
    case REQUEST_ID = 'REQUEST_ID';
    case IS_MOBILE = 'IS_MOBILE';
    case IS_PAD = 'IS_PAD';
    case IP = 'IP';
    case LANGUAGE_ID = 'LANGUAGE_ID';

    case AGENT_DATA = 'AGENT_DATA';

    // @see HttpLoggerCustom & ApiException
    case VENDOR = 'vendor';
    case VENDOR_INFO = 'vendor_info';
    case VENDOR_REQUEST = 'vendor_request';
    case VENDOR_RESPONSE = 'vendor_response';
}
