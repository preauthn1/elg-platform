<?php

declare(strict_types=1);

namespace App\Models;

use App\CacheKeys\CacheKeys;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\CacheTtl;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\WalletEnum;
use App\Facades\FileStorage;
use App\Helpers\Common;
use App\Models\Traits\HasActivityLog;
use App\Observers\MemberObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @knowledge rules/currency-and-wallet-currency
 *
 * @property int $id 系統編號
 * @property int $parent 上層代理系統編號(User)
 * @property string $username 姓名
 * @property string|null $username_bak
 * @property string|null $email 電子信箱
 * @property string|null $nickname 暱稱
 * @property string $password 密碼
 * @property string|null $payment_password 支付密碼
 * @property numeric $balance 平台餘額
 * @property numeric $balance_bak
 * @property string|null $invitation_code 邀請碼
 * @property string|null $device_id 推播設備號
 * @property string|null $device_type 設備種類
 * @property string $image 個人頭像
 * @property string|null $first_name
 * @property string|null $last_name 英文姓
 * @property string|null $birthday 出生年月日
 * @property string|null $mobile 聯繫號碼
 * @property int|null $timezone_id 時區系統編號
 * @property int|null $country_id 居住國家(FK_Country)
 * @property string|null $zip_code 郵政編碼
 * @property string|null $district 省/地區/郡
 * @property string|null $city 城鎮/城市
 * @property string|null $address 地址
 * @property int|null $register_country_id 註冊國家
 * @property int|null $register_ip 註冊IP
 * @property string|null $register_ip_detail
 * @property string|null $register_domain 註冊網域
 * @property int $type 會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員
 * @property int $previous_day_type 前一天會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員
 * @property int $status 帳號狀態 0:未認證,1:正常,2:鎖定(密碼輸入錯六次, 系統自動變更)
 * @property int $sys_lock 系統鎖定; 1:鎖定, 0:非解鎖
 * @property int $event_mode 是否進入活動鎖定模式0:未鎖定，大於0:events.id
 * @property int $change_to_single_wallet 代理是否由多錢包轉換為單一錢包0:否，1:是
 * @property int $is_validate 是否通過身份驗證
 * @property int $mobile_validate 是否通過手機驗證
 * @property int $email_validate 狀態 0:未認證,1:已認證
 * @property string|null $last_login 最後登入時間
 * @property int|null $last_login_ip 最後登入IP
 * @property string|null $first_deposit 首次儲值時間
 * @property string|null $memo 方便技術記錄用
 * @property int $has_2fa Google驗證器開關, 0:關閉, 1:開啟
 * @property int $ip_change_mobile_verify 登入IP變更手機驗證開關
 * @property int $ip_change_email_verify 登入IP變更email驗證開關
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property string|null $vworld_id vworld1.0的玩家編號(備用而已)
 * @property int $realname_validate
 * @property-read Collection<int, \Spatie\Activitylog\Models\Activity> $activitiesAsCauser
 * @property-read int|null $activities_as_causer_count
 * @property-read Collection<int, \Spatie\Activitylog\Models\Activity> $activitiesAsSubject
 * @property-read int|null $activities_as_subject_count
 * @property-read Agent|null $agent
 * @property-read Collection<int, AgentVendor> $agentVendors
 * @property-read int|null $agent_vendors_count
 * @property-read mixed $available_vendor_ids
 * @property-read Collection<int, MemberBankAccount> $bankAccount
 * @property-read int|null $bank_account_count
 * @property-read Country|null $country
 * @property-read int $currency_id
 * @property-read mixed $customized_setting_group_id
 * @property-read Collection<int, EventV2UserJoin> $eventV2Joins
 * @property-read int|null $event_v2_joins_count
 * @property-read mixed $is_event_v2
 * @property-read mixed $is_event_wallet
 * @property-read mixed $is_newbie_challenge_progress
 * @property-read mixed $is_tg_user
 * @property-read MemberAuthentication|null $memberAuthentication
 * @property-read MemberSetting|null $memberSetting
 * @property-read NewbieChallengeProgress|null $newbieChallengeProgress
 * @property-read MemberStatistic|null $statistics
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, MemberBindingLog> $phoneBindings
 * @property-read int|null $phone_bindings_count
 * @property-read Collection<int, PromotionWallet> $promotionWallets
 * @property-read int|null $promotion_wallets_count
 * @property-read mixed $rolling_chip
 * @property-read Collection<int, MemberTelegramRel> $telegramRels
 * @property-read int|null $telegram_rels_count
 *
 * @method static \Database\Factories\MemberFactory factory($count = null, $state = [])
 * @method static Builder<static>|Member newModelQuery()
 * @method static Builder<static>|Member newQuery()
 * @method static Builder<static>|Member query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(MemberObserver::class)]
class Member extends Authenticatable
{
    use HasActivityLog;
    use HasFactory;

    // use LogsActivity;
    use Notifiable;

    public bool $lockBalance = false;

    // public function getActivitylogOptions(): LogOptions
    // {
    //     return LogOptions::defaults()
    //         ->useLogName('member')
    //         ->logOnly([
    //             'username',
    //             'email',
    //             'nickname',
    //             'password',
    //             'payment_password',
    //             'balance',
    //             'first_name',
    //             'mobile',
    //             'status',
    //             'event_mode',
    //             'last_login',
    //             'last_login_ip',
    //         ])
    //         ->logOnlyDirty()
    //         ->dontLogIfAttributesChangedOnly(['updated_at'])
    //         ->dontSubmitEmptyLogs();
    // }

    protected array $cacheData = [];

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent', 'id');
    }

    public function memberGroup(): BelongsTo
    {
        return $this->belongsTo(MemberGroup::class, 'member_group_id');
    }

    public function telegramRels(): HasMany
    {
        return $this->hasMany(MemberTelegramRel::class);
    }

    public function memberSetting(): HasOne
    {
        return $this->hasOne(MemberSetting::class, 'member_id');
    }

    public function statistics(): HasOne
    {
        return $this->hasOne(MemberStatistic::class, 'member_id');
    }

    public function bankAccount(): HasMany
    {
        return $this->hasMany(MemberBankAccount::class, 'member_id');
    }

    /**
     * 會員持有的優惠錢包（已無使用）
     */
    public function promotionWallets(): HasMany
    {
        return $this->hasMany(PromotionWallet::class, 'member_id')
            ->where('status', 1);
    }

    /**
     * 是否應該使用活動錢包（CODE_EVENT_WALLET）：Event V2 活動中，或新人專享挑戰活動金流水階段
     */
    protected function isEventWallet(): Attribute
    {
        return Attribute::get(function () {
            return $this->isEventV2 || $this->isNewbieChallengeProgress;
        });
    }

    protected function isEventV2(): Attribute
    {
        return Attribute::get(function () {
            return $this->eventV2Joins->isNotEmpty();
        });
    }

    public function eventV2Joins(): HasMany
    {
        return $this->hasMany(EventV2UserJoin::class, 'member_id')->where('status', EventV2UserJoinStatusEnum::JOIN->value);
    }

    /**
     * 是否處於新人專享挑戰活動的活動錢包階段（挑戰流水中）
     */
    protected function isNewbieChallengeProgress(): Attribute
    {
        return Attribute::get(function () {
            return $this->newbieChallengeProgress()
                ->where('stage', NewbieChallengeStageEnum::CHALLENGING->value)
                ->exists();
        });
    }

    public function newbieChallengeProgress(): HasOne
    {
        return $this->hasOne(NewbieChallengeProgress::class, 'member_id');
    }

    /**
     * 是否為 Telegram 用戶
     */
    protected function isTgUser(): Attribute
    {
        return Attribute::get(function () {
            return Cache::remember(CacheKeys::isTgUser($this->id), CacheTtl::ONE_HOUR->value, function () {
                return (int) $this->telegramRels->isNotEmpty();
            });
        });
    }

    /**
     * 關聯: 會員所屬代理關聯的遊戲商
     */
    public function agentVendors(): HasManyThrough
    {
        return $this->hasManyThrough(
            AgentVendor::class, // 目標模型
            Agent::class,       // 中間模型
            'id',               // 中間模型的外鍵 (Agent 的 id)
            'agent_id',         // 目標模型的外鍵 (AgentVendor 的 agent_id)
            'parent',           // 當前模型的外鍵 (Member 的 parent)
            'id'                // 中間模型的本地鍵 (Agent 的 id)
        );
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function memberAuthentication(): HasOne
    {
        return $this->hasOne(MemberAuthentication::class, 'member_id');
    }

    /**
     * 修改器: customized_setting_group_id
     */
    protected function customizedSettingGroupId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->agent->customized_setting_group_id
        );
    }

    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? (Str::startsWith($value, 'https') ? $value : Storage::url($value)) : $value,
            set: function (string $value, array $attributes) {
                if (Str::startsWith($value, 'https')) {
                    return $value;
                }

                $oldRaw = $attributes['image'] ?? null;
                if ($oldRaw) {
                    $oldPath = Str::startsWith($oldRaw, 'https')
                        ? Str::after($oldRaw, rtrim(Storage::url(''), '/') . '/')
                        : $oldRaw;
                    Storage::delete($oldPath);
                }

                return FileStorage::storeBase64Image($value, 'avatars/' . $attributes['id'] . '_' . time());
            }
        );
    }

    /**
     * 修改器: 取得幣別 currency_id
     *
     * 幣別現階段錨在組織層（organizations.currency_id）；members 與 agents 皆無
     * 幣別欄位。取不到組織時回 0（sentinel）——六站實測有 organization_id 指向
     * 不存在列、與幣別未設定（0）的組織，直接讀 null 屬性會拋錯。
     */
    protected function currencyId(): Attribute
    {
        return Attribute::get(function (): int {
            return (int) ($this->agent?->organization?->currency_id ?? 0);
        });
    }

    /**
     * 修改器: rolling_chip 會員泥碼
     *
     * @deprecated
     */
    protected function rollingChip(): Attribute
    {
        return Attribute::get(function ($value) {
            $deadChipWallet = MemberWallet::query()
                ->where('member_id', $value)
                ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
                ->first();

            return $deadChipWallet ? Common::numFloor($deadChipWallet->balance, 4) : 0;
        });
    }

    /**
     * 修改器: available_vendor_ids 會員有效的遊戲商 ID
     *
     * 效果等同 App::make(VendorService::class)->getVendorIds($member->agent);
     */
    protected function availableVendorIds(): Attribute
    {
        return Attribute::get(function () {
            $agentVendors = $this->agentVendors();
            $agentGroupId = $this->agent->vendor_customize_group_id;

            return $agentVendors
                ->whereHas('vendor', function ($query) {
                    $query->where('status', 1);
                })
                ->whereHas('vendorCustomizedItems', function ($query) use ($agentGroupId) {
                    $query->where('agent_group_id', $agentGroupId)
                        ->where('status', 1);
                }) // 檢查 VendorCustomizedItems 條件
                ->whereHas('vendorInfo', function ($query) {
                    $query->where('status', 1);
                }) // 檢查 VendorInfo 條件
                ->groupBy(['vendor_id'])
                ->orderBy('vendor_id')
                ->pluck('vendor_id');
        });
    }

    public function setBalanceAttribute($value)
    {
        if ($this->lockBalance) {
            return;
        }

        $this->attributes['balance'] = $value;
    }

    public function phoneBindings(): HasMany
    {
        return $this->hasMany(MemberBindingLog::class, 'member_id')
            ->whereIn('type', [
                MemberBindingLogTypeEnum::UPDATE_PHONE->value,
                MemberBindingLogTypeEnum::BINDING_PHONE->value,
            ])
            ->where('status', 1);
    }
}
