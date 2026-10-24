<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_vendor_info_id event_vendor_infos.id
 * @property int $game_id games.id
 * @property int $is_new 是否為新遊戲;0:不是,1:是
 * @property int $is_hot 是否為熱門遊戲;0:不是,1:是
 * @property int $sort 排序
 * @property int $status 狀態0:停用,1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 管理員編號
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventVendorInfoGame newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventVendorInfoGame newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventVendorInfoGame query()
 *
 * @mixin \Eloquent
 */
class EventVendorInfoGame extends Model
{
    //
}
