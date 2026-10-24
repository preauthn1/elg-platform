<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\TransactionLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $transaction_no 單號
 * @property int|null $agent_id 代理系統編號(同一個玩家可能由不同代理開洗分)
 * @property int $member_id 會員系統編號
 * @property int|null $vendor_id 遊戲商系統編號
 * @property int|null $order_id 訂單系統編號
 * @property int $type 類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:打卡獎勵,11:拒絕洗分,12:退還洗分,13:活動獎勵,14:補帳,15:登入活動獎勵,16:回收登入活動獎勵,17:全民代理,18: v-jackpot,19:首充活動返利,20:註冊送,21:綁定手機送,22:預付網扣款,23:預付網退款,24:充值活動返利,25:每日充值活動返利,26:邀請玩家獎勵活動返利,30:w-jackpot,31:W-Jackpot 6d獎勵\n101~500:活動獎勵(101:slot comptition)
 * @property numeric $point 平台金額
 * @property numeric $tax 稅收
 * @property numeric $vendor_point Vendor金額
 * @property numeric $rate 平台點數與遊戲點數的比例
 * @property numeric $before_balance 变更前数点
 * @property numeric $balance 平台餘額
 * @property numeric|null $vendor_balance Vendor餘額
 * @property int $status 狀態編號
 * @property string|null $remark 備註
 * @property string|null $checksum 校驗碼
 * @property string $result 結果
 * @property string $operator 操作者
 * @property int $platform 操作平台0:前台，1:客服，2:代理後台
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|TransactionLog newModelQuery()
 * @method static Builder<static>|TransactionLog newQuery()
 * @method static Builder<static>|TransactionLog query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(TransactionLogObserver::class)]
class TransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $attributes = [
        'vendor_point' => 0.0000,
        'rate' => 1.0000,
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
