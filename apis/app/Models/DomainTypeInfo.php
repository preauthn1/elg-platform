<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $domain_type_id 網域類型系統編號,0為公版
 * @property int $template_id 模版系統編號,0為公版
 * @property int $mobile_templates 手機模版系統編號
 * @property int $pc_templates 電腦模版系統編號
 * @property string|null $logo_pc 電腦版Logo
 * @property string|null $logo_mobile 手機版Logo
 * @property string|null $icon favorite icon
 * @property string|null $add_home_image 添加主螢幕教學圖片網址
 * @property string|null $title 網站名稱
 * @property int|null $type 類型: 0:正常, 1:推廣用(因為可能會用到短網址, 故其他在回給前端可能不適合)
 * @property int $need_login 是否可使用公開頁面
 * @property string|null $info 其他資訊
 * @property string $memo 描述
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainTypeInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainTypeInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainTypeInfo query()
 *
 * @mixin \Eloquent
 */
class DomainTypeInfo extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
