<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id 訂單id
 * @property int $bank_id 銀行帳戶id
 *
 * ⚠️ 這裡的 bank_id 是 `bank_accounts.id`（自動入款收款帳戶），**不是 `banks.id`**——欄位名在本庫
 * 是重載的。兩者 id 區間重疊（bank_accounts 上界僅數百、banks 上千），誤把本表納入 banks 的
 * 引用重指會靜默把自動入款訂單指到錯的收款帳戶且不報錯，故銀行 rename／merge 類命令一律排除本表。
 * @property string $image 圖片
 * @property array<string, string>|null $bank_snapshot 建單當下的銀行戶口快照（bank_name / bank_code / account_nickname / account_name / account_no），舊單為 null
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|OrderImage newModelQuery()
 * @method static Builder<static>|OrderImage newQuery()
 * @method static Builder<static>|OrderImage query()
 *
 * @mixin \Eloquent
 */
class OrderImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'bank_snapshot' => 'array',
        ];
    }
}
