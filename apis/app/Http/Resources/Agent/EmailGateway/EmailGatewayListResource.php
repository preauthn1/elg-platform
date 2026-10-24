<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EmailGateway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailGatewayListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 電子郵件提供商ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var int 電子郵件群組ID(agent_groups.id)
             *
             * @example 4249
             **/
            'agent_group_id' => $this->agent_group_id,
            /**
             * @var string 組別名稱
             *
             * @example "Wintoto郵件配置"
             **/
            'agent_group_name' => $this->agent_group_name,
            /**
             * @var string 寄件信箱
             *
             * @example "user@example.invalid"
             **/
            'email' => $this->email,
            /**
             * @var string SMTP 主機
             *
             * @example "example.invalid"
             **/
            'host' => $this->host,
            /**
             * @var int SMTP 連接埠
             *
             * @example 587
             **/
            'port' => $this->port,
            /**
             * @var string 連線協定(smtp:STARTTLS, smtps:SSL)
             *
             * @example "smtp"
             **/
            'scheme' => $this->scheme,
            /**
             * @var string 寄件人顯示名稱
             *
             * @example "Example"
             **/
            'from_name' => $this->from_name,
            /**
             * @var int 排序
             *
             * @example 0
             **/
            'sort' => $this->sort,
            /**
             * @var int 狀態(0:停用, 1:啟用)
             *
             * @example 1
             **/
            'status' => $this->status,
            /**
             * @var string 更新者(代理帳號)
             *
             * @example "admin"
             **/
            'updated_by' => $this->updated_by,
            /**
             * @var string 更新時間(後台時區)
             *
             * @example "2026-07-28 15:00:00"
             **/
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
