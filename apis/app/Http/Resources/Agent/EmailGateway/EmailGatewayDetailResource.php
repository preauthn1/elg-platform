<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EmailGateway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailGatewayDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * 不回傳 password, 編輯時留空即維持原密碼
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
        ];
    }
}
