<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $name 方便識別的名稱
 * @property int $type 遊戲商環境，0:測試，1:正式
 * @property int $vendor_id 遊戲商系統編號
 * @property string $currency 幣種代碼（自由字串，直接進三方 payload；與 currency_id 並存，見 database/data/currency_aliases.php）
 * @property int $currency_id 標準幣別編號（currencies.id），0=尚未回填（sentinel，不用 NULL）
 * @property numeric $rate 對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形
 * @property numeric|null $transfer_min_amount
 * @property array<array-key, mixed> $info 連接資訊
 * @property int $prepared_account_number 預備帳號數量
 * @property string $account_prefix 帳號前綴
 * @property numeric $cost
 * @property string|null $remark
 * @property int $status 狀態，0:停用、1:啟用
 * @property int $rolling_chip_status 禮碼開關 1:開啟 0:關閉
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property numeric $deposit_limit 存款限制, 0 = 無限制
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorInfoGame> $vendorInfoGames
 * @property-read int|null $vendor_info_games_count
 *
 * @method static \Database\Factories\VendorInfoFactory factory($count = null, $state = [])
 * @method static Builder<static>|VendorInfo newModelQuery()
 * @method static Builder<static>|VendorInfo newQuery()
 * @method static Builder<static>|VendorInfo query()
 *
 * @mixin \Eloquent
 */
class VendorInfo extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array', // 只是用來標註 type hint, 實際會去 protected function info(): Attribute 處理
    ];

    /**
     * 關聯的遊戲
     */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'vendor_id', 'vendor_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vendorInfoGames(): HasMany
    {
        return $this->hasMany(VendorInfoGame::class, 'vendor_info_id');
    }

    protected function info(): Attribute
    {
        return Attribute::get(function ($value, $attributes) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            $createdAt = $attributes['created_at'] ?? $this->created_at ?? null;
            if (!$createdAt) {
                return [];
            }

            $key = md5($createdAt);
            $iv = date('UYm', strtotime($createdAt));
            $cryptText = base64_decode($value);
            $str = trim(openssl_decrypt($cryptText, 'aes-256-cbc', md5($key), OPENSSL_RAW_DATA, $iv));

            return json_decode($str, true) ?: [];
        });
    }
}
