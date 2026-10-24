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
 * @property int $id
 * @property string|null $name 商戶資訊名稱
 * @property int $vendor_id vendors.id
 * @property string $currency 幣種（自由字串，與 currency_id 並存）
 * @property int $currency_id 標準幣別編號（currencies.id），0=尚未回填（sentinel，不用 NULL），語意同 vendor_infos.currency_id
 * @property numeric $rate 轉換率
 * @property numeric $transfer_min_amount 最小轉帳金額
 * @property array<array-key, mixed> $info 連接資訊
 * @property string|null $image 圖片
 * @property string|null $app_icon app圖片
 * @property int $prepared_account_number 預備帳號數量
 * @property string $account_prefix 帳號前綴
 * @property int $status 狀態0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 管理員編號
 * @property-read Collection<int, EventVendorInfoGame> $eventVendorInfoGames
 * @property-read int|null $event_vendor_info_games_count
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|EventVendorInfo newModelQuery()
 * @method static Builder<static>|EventVendorInfo newQuery()
 * @method static Builder<static>|EventVendorInfo query()
 *
 * @mixin \Eloquent
 */
class EventVendorInfo extends Model
{
    use HasFactory;

    protected $table = 'event_vendor_infos';

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

    public function eventVendorInfoGames(): HasMany
    {
        return $this->hasMany(EventVendorInfoGame::class, 'event_vendor_info_id');
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
