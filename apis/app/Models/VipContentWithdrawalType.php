<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vip_content_id VIP系統編號
 * @property int $withdrawal_type_id 銀行系統編號
 * @property int $status 狀態 0:未領取 1:已領取
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read VipContent|null $vipContent
 * @property-read WithdrawalType|null $withdrawalType
 *
 * @method static Builder<static>|VipContentWithdrawalType newModelQuery()
 * @method static Builder<static>|VipContentWithdrawalType newQuery()
 * @method static Builder<static>|VipContentWithdrawalType query()
 *
 * @mixin \Eloquent
 */
class VipContentWithdrawalType extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vipContent()
    {
        return $this->belongsTo(VipContent::class);
    }

    public function withdrawalType()
    {
        return $this->belongsTo(WithdrawalType::class);
    }
}
