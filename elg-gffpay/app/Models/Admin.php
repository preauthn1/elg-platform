<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;

// use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $username
 * @property string $password
 * @property int $status 狀態 0:停用,1:啟用
 * @property string|null $email
 * @property string|null $email_verified_at
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Admin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Admin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Admin query()
 *
 * @mixin \Eloquent
 */
class Admin extends Authenticatable implements FilamentUser, HasName
{
    // use Notifiable;

    // 不可被批量賦值的欄位
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // Filament 後台使用 username 登入
    public function username(): string
    {
        return 'username';
    }

    // 密碼自動 hash
    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => bcrypt($value),
        );
    }

    // Filament 顯示名稱
    public function getFilamentName(): string
    {
        return $this->username;
    }

    /**
     * FilamentUser
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 1;
    }
}
