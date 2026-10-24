<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $language_text_code 多語系對應代碼
 * @property int $language_id 語言代碼
 * @property string $text 多語系文字
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LanguageText newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LanguageText newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LanguageText query()
 *
 * @mixin \Eloquent
 */
class LanguageText extends Model
{
    //
}
