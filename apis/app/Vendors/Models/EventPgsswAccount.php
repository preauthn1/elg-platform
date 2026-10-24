<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\EventVendorInfo;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * PGSSW 刷分排行榜活動專用帳號（`event_pgssw_accounts`）
 *
 * 遷移自 API_Frontend `EventPgsswAccount`；與 EventV2（`app/Services/EventV2/`）無關，
 * 服務對象是 `refresh_ranking/game_url`（ticket 4005）沿用舊系統的活動專用帳號池機制。
 *
 * @property int $id
 * @property int $event_vendor_info_id event_vendor_infos.id
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號，0 代表未被綁定
 * @property string $session_token 登入token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $admin
 * @property-read EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventPgsswAccount newModelQuery()
 * @method static Builder<static>|EventPgsswAccount newQuery()
 * @method static Builder<static>|EventPgsswAccount query()
 *
 * @mixin \Eloquent
 */
class EventPgsswAccount extends AbstractAccount
{
    protected $table = 'event_pgssw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
