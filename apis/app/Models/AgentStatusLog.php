<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentStatusLog extends Model
{
    /**
     * target_type 值域。
     */
    public const int TARGET_TYPE_AGENT = 1;

    public const int TARGET_TYPE_MEMBER = 2;

    /**
     * admin = 0 表系統自動觸發（排程、登入失敗自動鎖定）。
     */
    public const int ADMIN_SYSTEM = 0;

    protected $fillable = [
        'target_type',
        'target_id',
        'before_status',
        'after_status',
        'before_sys_lock',
        'after_sys_lock',
        'remark',
        'admin',
    ];
}
