<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @knowledge rules/payout-bank-code-mapping
 *
 * @property int $id
 * @property int $cash_flow_id 對應 cash_flows.id
 * @property int $currency_id 幣別 ID
 * @property int $bank_id 對應 banks.id（平台銀行主檔）
 * @property string $bank_code 我方銀行代碼，對應 banks.code
 * @property string $provider_bank_code 第三方金流提供的銀行代碼
 * @property Carbon|null $manual_override_at 人工覆寫 provider_bank_code 的時間；非 null 時 cash-flow:sync 不覆寫該欄
 * @property string|null $manual_override_note 人工覆寫的原因與出處
 * @property numeric $min_amount 渠道層級最低金額限制
 * @property numeric $max_amount 渠道層級最高金額限制（0 = 不限制）
 * @property int $status 狀態: 1=啟用, 0=停用
 * @property string|null $info 額外設定，如支行資訊等
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bank|null $bank
 * @property-read CashFlow|null $cashFlow
 * @property-read Currency|null $currency
 *
 * @method static Builder<static>|CashFlowBank newModelQuery()
 * @method static Builder<static>|CashFlowBank newQuery()
 * @method static Builder<static>|CashFlowBank query()
 *
 * @mixin \Eloquent
 */
class CashFlowBank extends Model
{
    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'manual_override_at' => 'datetime',
        ];
    }

    public function cashFlow(): BelongsTo
    {
        return $this->belongsTo(CashFlow::class, 'cash_flow_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
