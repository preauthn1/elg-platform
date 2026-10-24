<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\MemberBindingLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $type 類別; 1:修改手機, 2:修改email, 3:綁定email
 * @property int $member_id members.id
 * @property string|null $before_value 更換前的值
 * @property string|null $value 更換後的值
 * @property string $ip IP地址
 * @property int $validate_type 驗證類別; 1:手機, 2:email
 * @property int $status 狀態; 0:未成功, 1:成功, 2:失敗
 * @property string|null $remark 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|MemberBindingLog newModelQuery()
 * @method static Builder<static>|MemberBindingLog newQuery()
 * @method static Builder<static>|MemberBindingLog query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(MemberBindingLogObserver::class)]
class MemberBindingLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
