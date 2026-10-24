<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 風控IP限制（風控管理 > IP限制，4096）。
 *
 * 狀態啟用的 IP 依勾選的阻擋項目擋下前台行為：註冊(block_register)、登入(block_login)、
 * 簡訊驗證(block_sms)；登入項另含 AuthMember 踢線。enforcement 見 IpRestrictionGuard。
 * repeat_count 為被擋嘗試累計（不分項目），由 Guard 以 base builder 原子累加，不經 Eloquent timestamps。
 */
class IpRestriction extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** 最後編輯此規則的代理 */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
