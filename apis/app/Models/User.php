<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $parent 上層管理員系統編號(User)
 * @property string $username 姓名
 * @property string|null $nickname 暱稱
 * @property string $email 電子信箱
 * @property string $password 密碼
 * @property string|null $mobile 聯繫號碼
 * @property int|null $timezone_id 時區系統編號
 * @property int $status 帳號狀態 0:未認證,1:正常,2:鎖定
 * @property UserRoleEnum $role 角色 1:admin,2:superadmin
 * @property string|null $last_login 最後登入時間
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 更新者ID
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User query()
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * FilamentUser
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatusEnum::NORMAL->value;
    }

    /**
     * HasName
     */
    public function getFilamentName(): string
    {
        return filled($this->nickname) ? $this->nickname : $this->username;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRoleEnum::SUPERADMIN;
    }

    /**
     * 能否進用戶管理、改別人的角色：RD（ADMIN）與 SUPERADMIN 可以，運維不行。
     * 升成／降離 SUPERADMIN 另限 SUPERADMIN（見 UserRoleEnum::assignableBy()）。
     */
    public function canManageUsers(): bool
    {
        return $this->role !== UserRoleEnum::OPS;
    }

    /**
     * 操作頁日期欄位是否套較短的「只能回溯 MAX_DAYS_AGO 天」限制：運維受限，RD（ADMIN）與 SUPERADMIN 改套較長的 ADMIN_MAX_DAYS_AGO（舊月份對帳補帳需要）。
     */
    public function isLimitedToRecentOperationDates(): bool
    {
        return $this->role === UserRoleEnum::OPS;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRoleEnum::class,
        ];
    }
}
