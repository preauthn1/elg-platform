<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @knowledge rules/standard-bank-list
 *
 * @property int $id
 * @property string $code
 * @property int $currency_id
 * @property string|null $currency_code
 * @property int $type
 * @property string $name
 * @property string|null $swift
 * @property array|null $aliases
 * @property string|null $remark
 * @property int $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Bank> $banks
 * @property-read int|null $banks_count
 * @property-read Currency|null $currency
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandardBank newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandardBank newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandardBank query()
 *
 * @mixin \Eloquent
 */
class StandardBank extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'aliases' => 'array',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id', 'id');
    }

    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class, 'standard_bank_id', 'id');
    }
}
