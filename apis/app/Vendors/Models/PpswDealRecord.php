<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $trace_id 追蹤碼
 * @property string $username 玩家帳號 playerId
 * @property string $bet_id 注單號 roundId
 * @property string $reference_no 三方交易碼
 * @property string $action 請求動作
 * @property string $request 原始請求
 * @property int $status 狀態 0:失敗,1:成功
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|PpswDealRecord newModelQuery()
 * @method static Builder<static>|PpswDealRecord newQuery()
 * @method static Builder<static>|PpswDealRecord query()
 *
 * @mixin \Eloquent
 */
class PpswDealRecord extends Model
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
