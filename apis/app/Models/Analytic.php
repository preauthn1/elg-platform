<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id 系統編號
 * @property int $agent_id 代理系統編號,0為平台資料
 * @property int $type 類型
 * @property string $code 程式碼
 * @property int $status 狀態 0:停用,1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Analytic newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Analytic newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Analytic query()
 *
 * @mixin \Eloquent
 */
class Analytic extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
