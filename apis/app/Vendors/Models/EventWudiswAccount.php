<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\EventVendorInfo;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WudiSW 刷分排行榜活動專用帳號（`event_wudisw_accounts`）
 *
 * 遷移自 API_Frontend `EventWudiswAccount`；與 EventV2（`app/Services/EventV2/`）無關，
 * 服務對象是 `refresh_ranking/game_url`（ticket 4005）沿用舊系統的活動專用帳號池機制。
 *
 * 與一般帳號 {@see WudiswAccount} 是**平行**關係（同樣直接繼承 AbstractAccount），
 * 不是繼承關係：兩者對應不同資料表、不同帳號池。
 *
 * 注意：此表**無 `status` 欄位**（對照 `wudisw_accounts` 有），認領時不可加 status 條件。
 *
 * @property int $id
 * @property int $event_vendor_info_id 商戶編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號，0 代表未被綁定
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $admin
 * @property-read EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventWudiswAccount newModelQuery()
 * @method static Builder<static>|EventWudiswAccount newQuery()
 * @method static Builder<static>|EventWudiswAccount query()
 *
 * @mixin \Eloquent
 */
class EventWudiswAccount extends AbstractAccount
{
    protected $table = 'event_wudisw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
