<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberOnlineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => (int) $this->member_id,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'username' => $this->username,
            /**
             * @var string|null 真實姓名
             *
             * @example "王小明"
             */
            'first_name' => $this->first_name,
            /**
             * @var string|null 會員群組名稱
             *
             * @example "Regular"
             */
            'group_name' => $this->group_name,
            /**
             * @var string|null 最後登入時間（UTC）
             *
             * @example "2026-08-04 06:03:23"
             */
            'last_login' => $this->last_login,
            /**
             * @var string|null 最後登入IP
             *
             * @example "0.0.0.0"
             */
            'last_login_ip' => $this->last_login_ip ? long2ip((int) $this->last_login_ip) : null,
            /**
             * @var string|null 最後活動時間（UTC）
             *
             * @example "2026-08-04 06:10:00"
             */
            'last_activity_at' => $this->last_activity_at,
            /**
             * @var int|null 上線秒數（最後活動時間 - 最後登入時間，供前端格式化為 X小時Y分鐘）
             *
             * @example 7200
             */
            'online_seconds' => ($this->last_login && $this->last_activity_at)
                ? max(0, strtotime($this->last_activity_at . ' UTC') - strtotime($this->last_login . ' UTC'))
                : null,
        ];
    }
}
