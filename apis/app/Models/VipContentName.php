<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $language_id 語系系統編號
 * @property int $vip_content_id VIP系統編號
 * @property string|null $name VIP名稱
 * @property string|null $image 圖片
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|VipContentName newModelQuery()
 * @method static Builder<static>|VipContentName newQuery()
 * @method static Builder<static>|VipContentName query()
 *
 * @mixin \Eloquent
 */
class VipContentName extends Model
{
    //
}
