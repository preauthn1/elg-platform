<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property int $rowID 唯一id
 * @property int $activity_id 活動id
 * @property numeric $betamount 投注金額
 * @property numeric $winamount 贏錢金額
 * @property int $money_type 金錢類型，1=>現金，2=>虛擬幣
 * @property int $currency_id 幣種id
 * @property numeric $exchange_rate 換人民幣匯率
 * @property int $show_scale 幣種顯示縮放比例
 * @property string $desc 描述
 * @property string $create_time 生成時間
 * @property string $username 用戶名
 * @property string $userip 下注ip
 * @property string $cdnip 下注的cdnip
 * @property int $client_type 客戶使用的客戶端 1-pc 2-wap 3-app
 * @property string $order_id 活動注單號
 * @property string $activity_name 活動名稱
 * @property string|null $utc_create_time 遊戲時間(UTC+0)
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IaBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IaBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IaBetRecord query()
 *
 * @mixin \Eloquent
 */
class IaBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betamount';

    protected ?string $validBetColumn = null;

    // winamount 為贏得金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winamount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'rowID';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'create_time';

    protected ?string $vendorUsernameColumn = 'username';
}
