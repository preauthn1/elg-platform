<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 電子郵件提供商(SMTP)設定
 *
 * @property int $id 流水號
 * @property int $agent_group_id 電子郵件群組ID(agent_groups.id, agent_group_type=7)
 * @property string $email 寄件信箱, 同時作為 SMTP 帳號
 * @property string $password SMTP 密碼(加密後)
 * @property string $host SMTP 主機
 * @property int $port SMTP 連接埠
 * @property string $scheme 連線協定; smtp:STARTTLS, smtps:SSL
 * @property string $from_name 寄件人顯示名稱
 * @property int $sort 排序
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID(agents.id)
 *
 * @method static Builder<static>|EmailGateway newModelQuery()
 * @method static Builder<static>|EmailGateway newQuery()
 * @method static Builder<static>|EmailGateway query()
 *
 * @mixin \Eloquent
 */
class EmailGateway extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', StatusEnum::ENABLE->value);
    }
}
