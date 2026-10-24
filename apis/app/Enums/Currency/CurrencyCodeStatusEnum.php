<?php

declare(strict_types=1);

namespace App\Enums\Currency;

/**
 * vendor_infos.currency 這類自由字串經 alias 契約解析後的判定結果。
 *
 * 只有 MAPPED／NON_CURRENCY／CRYPTO 三種帶得出 currency_id；其餘四種都對不到，
 * 回填必須跳過、稽核列為落空。判定順序見 CurrencyCodeResolver::classify()。
 */
enum CurrencyCodeStatusEnum: string
{
    /** CurrencyEnum 有同名 case，且 currencies 主檔在該 id 就是這個 code */
    case MAPPED = 'mapped';

    /** 站台點數、電子錢包通道：語意上不是幣別，但 Enum 與主檔都有列，回填得到值 */
    case NON_CURRENCY = 'non_currency';

    /** 虛擬貨幣（USDT／TON／BUSD）：各自是獨立幣別、可作為錢包幣別（2026-09-17 決議㉚，撤回決議㉖），帶得出自己的 currency_id；稽核單獨標示以便與法幣區分 */
    case CRYPTO = 'crypto';

    /** 對照表 unresolved 段：語意未查明，等裁決 */
    case UNRESOLVED = 'unresolved';

    /** CurrencyEnum 有 case、目標庫主檔沒那一列（版控先補、seeder 還沒跑） */
    case ENUM_ONLY = 'enum_only';

    /** 主檔在 Enum 的 id 上放的是別的 code（例如後台手建佔走） */
    case ID_MISMATCH = 'id_mismatch';

    /** 已知標準法幣，但主檔與 Enum 都還沒建（對照表 pending_standard 段） */
    case PENDING_SEED = 'pending_seed';

    /** 對照表完全沒收錄的代碼 */
    case UNKNOWN = 'unknown';
}
