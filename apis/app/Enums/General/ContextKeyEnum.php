<?php

declare(strict_types=1);

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
    case ORIENTATION = 'ORIENTATION';
    case IP = 'IP';
    case LANGUAGE_ID = 'LANGUAGE_ID';

    case AGENT_DATA = 'AGENT_DATA';

    // @see HttpLoggerCustom & ApiException
    case VENDOR = 'vendor';
    case VENDOR_INFO = 'vendor_info';

    // @see PaymentService
    case AGENT_CASH_FLOW = 'agent_cash_flow';

    // @see RunAdminOperationJob：後台觸發的 artisan 指令據此讀回自己的操作紀錄（中斷旗標）
    case ADMIN_OPERATION_LOG_ID = 'admin_operation_log_id';

    // @see FetchVendorBetRecord：流程標記，隨 Context 附掛到鏈上所有 log 的 extra
    case FLOW = 'flow';
}
