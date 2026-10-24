<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $agent_group_id 代理組別ID
 * @property int $type 類別 1. 投注額 2. 輸分
 * @property int $vendor_id 遊戲商ID
 * @property int $game_type_id 遊戲類別
 * @property numeric $proportion_1 自身返利%數
 * @property numeric $proportion_2 上層返利%數
 * @property numeric $proportion_3 上上層返利%數
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThreeHierarchyProportion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThreeHierarchyProportion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThreeHierarchyProportion query()
 *
 * @mixin \Eloquent
 */
class ThreeHierarchyProportion extends Model
{
    protected $table = 'three_hierarchy_proportions';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
