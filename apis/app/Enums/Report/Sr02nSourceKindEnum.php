<?php

declare(strict_types=1);

namespace App\Enums\Report;

/**
 * SR02N「來源」欄的種類代碼：cash_flow 時另附金流名稱，其餘由前端翻譯。
 */
enum Sr02nSourceKindEnum: string
{
    case CASH_FLOW = 'cash_flow';
    case CUSTOMER_SERVICE = 'customer_service';
    case SYSTEM = 'system';
    case OTA = 'ota';
}
