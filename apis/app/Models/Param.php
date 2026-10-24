<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $type 類型
 * @property string $parameter 參數
 * @property int $value 值
 * @property string|null $info
 * @property int $sort 排序
 * @property int $status 狀態 0:停用,1:啟用
 * @property string $remark 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|Param newModelQuery()
 * @method static Builder<static>|Param newQuery()
 * @method static Builder<static>|Param query()
 *
 * @mixin \Eloquent
 */
class Param extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
