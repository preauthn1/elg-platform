<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\WalletTransactionLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $transaction_no 單號
 * @property int $wallet_type_id 錢包類別ID
 * @property int $agent_id agents.id
 * @property int $member_id members.id
 * @property int $vendor_id vendors.id
 * @property int $order_id orders.id
 * @property int $type 類別
 * @property numeric $operator_before_balance 操作者變更前點數(代理)
 * @property numeric $operator_balance 操作者餘額(代理)
 * @property numeric $point 平台金額
 * @property numeric $vendor_point 遊戲商金額
 * @property numeric $tax 稅收
 * @property numeric $rate 平台點數與遊戲點數的比例
 * @property numeric $before_balance 變更前點數
 * @property numeric $balance 平台餘額
 * @property numeric $vendor_balance 遊戲商餘額
 * @property int $status 狀態; 0: 失敗, 1000: 成功
 * @property string $remark 備註
 * @property string $checksum 校驗碼
 * @property string $result 結果
 * @property string $operator 操作者
 * @property int $platform 操作平台0:前台，1:客服，2:代理後台
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Agent|null $agent
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|WalletTransactionLog newModelQuery()
 * @method static Builder<static>|WalletTransactionLog newQuery()
 * @method static Builder<static>|WalletTransactionLog query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(WalletTransactionLogObserver::class)]
class WalletTransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 模型屬性的預設值
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'remark' => '',
        'checksum' => '',
        'result' => '',
        'operator' => '',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
