<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property string|null $session_token 遊戲 session token
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|SexyslotswAccount newModelQuery()
 * @method static Builder<static>|SexyslotswAccount newQuery()
 * @method static Builder<static>|SexyslotswAccount query()
 *
 * @mixin \Eloquent
 */
class SexyslotswAccount extends AbstractAccount
{
    protected $table = 'sexyslotsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
