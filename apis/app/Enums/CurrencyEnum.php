<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * @knowledge rules/currency-and-wallet-currency
 */
enum CurrencyEnum: int
{
    case MYR = 1;
    case SGD = 2;
    case VND = 3;
    case VND2 = 4;
    case KVND = 5;
    case IDR = 6;
    case IDR2 = 7;
    case KIDR = 8;
    case THB = 9;
    case USDT = 10;
    case FON = 11;
    case GCASH = 12;
    case BUSD = 13;
    case MMK = 14;
    case PayMaya = 15;
    case HKD = 16;
    case PGK = 17;
    case AUD = 18;
    case BDT = 19;
    case TON = 20;
    case INR = 21;
    case PHP = 22;
    case USD = 23;
    case CNY = 24;

    /**
     * 可作為會員錢包幣別的白名單。
     *
     * 判定的是「這個 currency_id 能不能開一個會員錢包」，與「字串解析得到哪個 currency_id」
     * 是兩件事——後者屬 App\Services\Currency\CurrencyCodeResolver（解析鏈與判準見該 class 的
     * docblock），本方法不做任何字串比對。
     *
     * @return list<self>
     */
    public static function walletAllowed(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => !in_array($case, self::walletExcluded(), true),
        ));
    }

    public static function isWalletAllowed(int $currencyId): bool
    {
        $case = self::tryFrom($currencyId);

        return $case !== null && !in_array($case, self::walletExcluded(), true);
    }

    /**
     * 排除在錢包幣別之外的兩類代碼。
     *
     * - 單位變體 VND2(4)／KVND(5)／IDR2(7)／KIDR(8)：千分制與仙位一律用 vendor_infos.rate
     *   在遊戲商層表達，不佔獨立錢包。放行會讓同一種幣別出現兩個尺度差 1000 倍的錢包。（決議三）
     * - 非幣別 FON(11)／GCASH(12)／PayMaya(15)：站台點數與菲律賓電子錢包通道。
     *   ⚠️ 若業務確認 GCASH／PayMaya 要能當錢包幣別，改這裡（排除可逆，誤放行不可逆）。
     * 虛擬貨幣 USDT(10)／TON(20)／BUSD(13) 不在排除之列：決議㉚（2026-09-17，撤回決議㉖）定案
     * USDT 是獨立錢包幣別、不對應 USD，不錨定美元的虛擬貨幣也可以開戶。要不要開放給某一群代理，
     * 由 agent_group_currencies 的勾選決定，這裡不寫死。
     * ⚠️ 這份排除名單是過渡做法：決議㉛ 的幣別主檔清洗完成後，上面兩類代碼會從主檔與本 Enum
     * 一併移除，屆時本方法與排除名單整個拿掉。
     *
     * 🔴 PGK(17) 不排除。初版曾以「主檔 status=0」為由把它一併排除，但 2026-08-28 六站
     *   全量實測為 TPower 0／VWorld 0／Yes2Win 1／AlfaWin77 1／Me77 1／VPlus33 1
     *   ——四站啟用中，且 PGK 是 ISO 4217 標準法幣（在別名契約的 standard 清單內）。
     *   排除它會讓那四站的巴布亞紐幾內亞基那錢包建不出來。
     *
     * @return list<self>
     */
    private static function walletExcluded(): array
    {
        return [
            self::VND2, self::KVND, self::IDR2, self::KIDR,
            self::FON, self::GCASH, self::PayMaya,
        ];
    }
}
