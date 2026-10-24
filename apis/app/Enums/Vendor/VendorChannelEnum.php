<?php

declare(strict_types=1);

namespace App\Enums\Vendor;

/**
 * 接入通道：集成遊戲商經由哪一家聚合商接到我方，值為聚合商的對外名稱。
 *
 * - EliteHub 即代碼中的 TTHSW（行文與介面一律寫 EliteHub，識別字照用）。
 * - 直連（不經聚合商）不是本列舉的值；廠商管理列表以字串 `direct` 表示。
 * - case 宣告順序即下拉選單的排列順序（direct 固定排第一，見 VendorDriverCapabilityService::availableChannels）。
 *
 * @knowledge rules/vendor-and-channel-management
 */
enum VendorChannelEnum: string
{
    case TITAN_HUB = 'TitanHub';

    case TOP55SW = 'Top55SW';

    case ELITE_HUB = 'EliteHub';

    case GMSW = 'GMSW';

    case target = 'target';

    case PGSSW = 'PGSSW';

    case DCT = 'DCT';
}
