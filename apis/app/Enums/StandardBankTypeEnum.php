<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * standard_banks.type：可收款對象的類型。
 *
 * 與 banks.type 的前三個值同語意（該欄 FK 至 withdrawal_types）。標準銀行分檔
 * （app/Console/Commands/CashFlow/Data/banks/{幣別}.php）條目未標 type 即為 BANK；
 * 電子錢包與手機號 proxy 收款（DuitNow / PayNow）標 E_WALLET。
 */
enum StandardBankTypeEnum: int
{
    case BANK = 1;   // 銀行帳號

    case E_WALLET = 2;   // 手機/電子錢包

    case CRYPTO = 3;   // 加密貨幣
}
