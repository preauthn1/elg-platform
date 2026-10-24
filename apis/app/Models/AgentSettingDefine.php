<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentSettingDefineObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $setting_id PK，非自動流水號
 * @property int $parent 若是type2，會記錄上層分類id
 * @property int $type type: 1 鍵值分類，type 2: 鍵值 (分類相同的設置是整組一起編輯的)
 * @property int $is_visible 是否後台可看到編輯
 * @property int|null $agent_edit 允許代理編輯
 * @property string|null $memo 備註說明
 * @property string $language_text_code 多語系對應代碼
 * @property string|null $default_value 首次編輯要帶入的預設值
 * @property string|null $data_type 資料類型
 * @property int $is_data_required 此類型設置是否為必填
 * @property string|null $enable_agents 是否僅有特定代理可編輯此項目
 * @property int $sort 排序
 * @property Carbon $created_at 建立日期
 * @property int $created_by 建立人
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentSettingDefine newModelQuery()
 * @method static Builder<static>|AgentSettingDefine newQuery()
 * @method static Builder<static>|AgentSettingDefine query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentSettingDefineObserver::class)]
class AgentSettingDefine extends Model
{
    /**
     * 表無 id 欄位，主鍵為人工指定的 setting_id（非自增）；
     * 未宣告時 Eloquent 以預設 id 組 UPDATE/DELETE 條件會直接 SQL 錯誤。
     */
    protected $primaryKey = 'setting_id';

    public $incrementing = false;

    protected $guarded = [
        'created_at',
        'updated_at',
    ];
}
