<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\EventVendorInfo;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * UUSlotSW 刷分排行榜活動專用帳號（`event_uuslotsw_accounts`）
 *
 * 遷移自 API_Frontend `EventUuslotswAccount`；與 EventV2（`app/Services/EventV2/`）無關，
 * 服務對象是 `refresh_ranking/game_url`（ticket 4005）沿用舊系統的活動專用帳號池機制。
 *
 * 與一般帳號 {@see UUSlotswAccount} 是**平行**關係（同樣直接繼承 AbstractAccount），
 * 不是繼承關係：兩者對應不同資料表、不同帳號池，「事件帳號 IS-A 一般帳號」語意不成立。
 *
 * @property int $id
 * @property int $event_vendor_info_id event_vendor_infos.id
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號，0 代表未被綁定
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $admin
 * @property-read EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventUuslotswAccount newModelQuery()
 * @method static Builder<static>|EventUuslotswAccount newQuery()
 * @method static Builder<static>|EventUuslotswAccount query()
 *
 * @mixin \Eloquent
 */
class EventUuslotswAccount extends AbstractAccount
{
    protected $table = 'event_uuslotsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
