<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $username pgssw_accounts.username
 * @property string $transaction_no transactionNo
 * @property int $game_id 投注游戏
 * @property int $status 處理狀態 0:待處理,1:成功,2:失敗
 * @property string|null $response_data response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswCancelLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswCancelLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswCancelLog query()
 *
 * @mixin \Eloquent
 */
class PgsswCancelLog extends Model
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
