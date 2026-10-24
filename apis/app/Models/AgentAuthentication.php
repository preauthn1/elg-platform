<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $agent_id 主帳號的系統編號(不等於0就是子帳號)
 * @property int $type 類型; 1:Google2FA
 * @property string|null $secret secret key
 * @property string|null $image img
 * @property int $status 帳號狀態 0:停用,1:啟用
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 更新者ID
 * @property string $google2fa_secret
 *
 * @method static Builder<static>|AgentAuthentication newModelQuery()
 * @method static Builder<static>|AgentAuthentication newQuery()
 * @method static Builder<static>|AgentAuthentication query()
 *
 * @mixin \Eloquent
 */
class AgentAuthentication extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'secret',
    ];

    public function setGoogle2faSecretAttribute($value): void
    {
        $this->attributes['secret'] = encrypt($value);
    }

    /**
     * Decrypt the user's google_2fa secret.
     */
    public function getGoogle2faSecretAttribute(string $value): string
    {
        return decrypt($value);
    }
}
