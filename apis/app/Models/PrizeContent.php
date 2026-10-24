<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $prize_id 獎勵系統編號
 * @property int $type 點數類型; 0:固定金額, 1:%數
 * @property string $name 獎項名稱
 * @property int $number 獎項數量
 * @property int $level
 * @property int $distribute 派發數量
 * @property int|null $sent 已送出
 * @property int $achieve_people 需要達標人數
 * @property numeric $achieve_point 多獎勵達標金額
 * @property int $percentage 中獎機率
 * @property int $point_type 1:平台分數 2:禮碼
 * @property int $point 平台點數
 * @property int|null $random_point_max 隨機獎金上限
 * @property numeric $point_percentage %數
 * @property int $flow_limit_multiple 稽核流水倍數
 * @property int $status 狀態0:停用1:使用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, PrizeContent> $prizeContents
 * @property-read int|null $prize_contents_count
 *
 * @method static Builder<static>|PrizeContent newModelQuery()
 * @method static Builder<static>|PrizeContent newQuery()
 * @method static Builder<static>|PrizeContent query()
 *
 * @mixin \Eloquent
 */
class PrizeContent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function prizeContents(): HasMany
    {
        return $this->hasMany(PrizeContent::class);
    }
}
