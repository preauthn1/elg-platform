<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 代理群組編號
 * @property int $type 類型; 1:銀行號碼, 2:電子錢包, 3:加密貨幣, 4:外幣銀行, 5:未知, 6:澳洲銀行, 7:澳洲PayId
 * @property string $name
 * @property string $code 銀行代碼
 * @property string|null $third_party_code 三方銀行代碼
 * @property int|null $currency_id
 * @property int|null $standard_bank_id 對應 standard_banks.id（bank:sync-mappings 回填，未回填為 null）
 * @property array<array-key, mixed>|null $info 相關資訊(json)
 * @property numeric $rate 匯率(2025/05/05提升至9位)
 * @property int $tax_type 手續費種類
 * @property numeric $tax 手續費
 * @property numeric $min_amount
 * @property numeric $max_amount
 * @property int $sort 排序
 * @property string|null $image_url 銀行icon
 * @property int $status 狀態
 * @property string|null $deleted_at
 * @property string|null $remark
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read BankName|null $bankName
 * @property-read Currency|null $currency
 * @property-read Collection<int, BankName> $names
 * @property-read int|null $names_count
 * @property-read StandardBank|null $standardBank
 * @property-read WithdrawalType|null $withdrawalType
 *
 * @method static Builder<static>|Bank newModelQuery()
 * @method static Builder<static>|Bank newQuery()
 * @method static Builder<static>|Bank query()
 *
 * @mixin \Eloquent
 */
class Bank extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
    ];

    public function withdrawalType(): BelongsTo
    {
        return $this->belongsTo(WithdrawalType::class, 'type', 'id')->where('status', 1);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id', 'id')->where('status', 1);
    }

    public function names(): HasMany
    {
        return $this->hasMany(BankName::class, 'bank_id', 'id');
    }

    public function bankName(): HasOne
    {
        return $this->hasOne(BankName::class, 'bank_id', 'id');
    }

    /**
     * 對應 standard_banks 主檔（過渡期由 cash-flow:seed-standard-banks ＋ bank:sync-mappings 回填）。
     * Phase 5 退役 banks 後，banks 表與本關聯隨之消失。
     */
    public function standardBank(): BelongsTo
    {
        return $this->belongsTo(StandardBank::class, 'standard_bank_id', 'id');
    }
}
