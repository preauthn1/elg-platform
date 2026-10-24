<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $code 檔案名稱
 * @property string $name 選單功能名稱
 * @property int $parent 代理後台選單系統編號，0為root menu
 * @property string $url 選單網址
 * @property string|null $symbol 選單符號
 * @property string $action 功能
 * @property int $sort 排序
 * @property int|null $status 狀態
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentMenu newModelQuery()
 * @method static Builder<static>|AgentMenu newQuery()
 * @method static Builder<static>|AgentMenu query()
 *
 * @mixin \Eloquent
 */
class AgentMenu extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
