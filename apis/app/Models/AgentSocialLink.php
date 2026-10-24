<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 社群入口配置 工單4106
 *
 * 前台展示的社群 icon（TG／FB／APK 下載等）。綁在總代身上，
 * 該線路下的所有代理共用同一份設定。
 *
 * @property int $id 系統編號
 * @property int $agent_id 所屬總代 agents.id (role_id=11)
 * @property string $name 顯示名稱
 * @property int $link_type 連結類型 1:網址(點擊跳轉) 2:檔案(點擊下載)
 * @property null|string $link_url 連結網址，或上傳後的檔案 URL
 * @property null|string $icon_url 前台顯示的 icon 圖片 URL
 * @property int $sort 顯示順序，小的在前
 * @property int $status 狀態 0:關閉 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentSocialLink newModelQuery()
 * @method static Builder<static>|AgentSocialLink newQuery()
 * @method static Builder<static>|AgentSocialLink query()
 *
 * @mixin \Eloquent
 */
class AgentSocialLink extends Model
{
    /** 每個總代最多可設定的筆數 */
    public const int MAX_PER_AGENT = 10;

    /** 連結類型：網址，前台點擊後跳轉 */
    public const int LINK_TYPE_URL = 1;

    /** 連結類型：檔案，前台點擊後下載（如 APK） */
    public const int LINK_TYPE_FILE = 2;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => 'integer',
            'sort' => 'integer',
            'status' => 'integer',
        ];
    }

    /** 所屬總代 */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
