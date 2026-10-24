<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name 網域類型名稱
 * @property string $memo 描述
 * @property int $status 0未使用,1使用中
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read DomainTypeInfo|null $infos
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainType query()
 *
 * @mixin \Eloquent
 */
class DomainType extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // 類型關聯
    public function infos(): BelongsTo
    {
        return $this->belongsTo(DomainTypeInfo::class, 'id', 'domain_type_id');
    }

    // public function info()
    // {
    //     return $this->hasOne(DomainTypeInfo::class, 'domain_type_id');
    // }

}
