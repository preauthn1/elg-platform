<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id 流水號
 * @property int $member_id 會員系統編號
 * @property string $title 標題
 * @property string $content 內容
 * @property string $url click action網址
 * @property string|null $multicast_id multicast_id
 * @property string|null $message_id message_id
 * @property string|null $error 錯誤訊息
 * @property int $status 狀態0:未發送,1:發送成功,2:發送失敗
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FirebaseCloudMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FirebaseCloudMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FirebaseCloudMessage query()
 *
 * @mixin \Eloquent
 */
class FirebaseCloudMessage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
