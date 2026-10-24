<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $transaction_no 單號
 * @property int $agent_id agents.id
 * @property int $member_id members.id
 * @property int $event_id events.id
 * @property int $promotion_wallet_id promotion_wallets.id
 * @property int $vendor_id vendors.id
 * @property int $order_id 訂單系統編號
 * @property int $type 類型1:平台轉入遊戲商,2:遊戲商轉入平台
 * @property numeric $point 平台金額
 * @property numeric $vendor_point 遊戲商金額
 * @property numeric $rate 平台點數與遊戲點數的比例
 * @property numeric $before_balance 變更前金額
 * @property numeric $balance 平台餘額
 * @property numeric $vendor_balance 遊戲商餘額
 * @property int $status 狀態編號
 * @property string $remark 備註
 * @property string $result 結果
 * @property string $operator 操作者
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 *
 * @method static Builder<static>|PromotionWalletLog newModelQuery()
 * @method static Builder<static>|PromotionWalletLog newQuery()
 * @method static Builder<static>|PromotionWalletLog query()
 *
 * @mixin \Eloquent
 */
class PromotionWalletLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
