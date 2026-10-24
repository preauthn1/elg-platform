<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $information_id informations.id
 * @property int $language languages.id
 * @property string $image 圖片
 * @property string $image_pc PC版圖片
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 *
 * @method static Builder<static>|InformationLanguage newModelQuery()
 * @method static Builder<static>|InformationLanguage newQuery()
 * @method static Builder<static>|InformationLanguage query()
 *
 * @mixin \Eloquent
 */
class InformationLanguage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
