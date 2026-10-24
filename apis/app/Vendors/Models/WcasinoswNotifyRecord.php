<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $bet_id 交易 id, serialnumber from request
 * @property string $notify_id 通知 id, notifyid from request
 * @property int $type 請求類型, type from request
 * @property int $is_done 是否完成, 0: 否, 1: 是
 * @property int $is_rollback 是否為撤單, 0: 否, 1: 是
 * @property numeric $amount 金額
 * @property string|null $infos 詳細資訊
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WcasinoswNotifyRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WcasinoswNotifyRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WcasinoswNotifyRecord query()
 *
 * @mixin \Eloquent
 */
class WcasinoswNotifyRecord extends Model
{
    protected ?string $vendorUsernameColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
