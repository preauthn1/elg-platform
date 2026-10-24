<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $bet_id 注單號 betId
 * @property string $reference_no
 * @property string $action 請求動作
 * @property string $trace_id 日誌追蹤碼
 * @property int $status 狀態 0:失敗,1:成功
 * @property string|null $message
 * @property string $request 原始請求
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|Nex4dswDealRecord newModelQuery()
 * @method static Builder<static>|Nex4dswDealRecord newQuery()
 * @method static Builder<static>|Nex4dswDealRecord query()
 *
 * @mixin \Eloquent
 */
class Nex4dswDealRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
