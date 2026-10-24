<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EmailGateway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailGatewayRecordResource extends JsonResource
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
             * @var int 發送編號
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 發送時間(後台時區)
             *
             * @example "2026-07-29 15:00:00"
             **/
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
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
            'sender' => $this->sender,
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             **/
            'member_username' => $this->member_username,
            /**
             * @var string 收件信箱
             *
             * @example "user@example.invalid"
             **/
            'receiver' => $this->receiver,
            /**
             * @var string 郵件主旨
             *
             * @example "Verification Code"
             **/
            'subject' => $this->subject,
            /**
             * @var string 郵件內容
             *
             * @example "The PIN is 123456"
             **/
            'message_content' => $this->message_content,
            /**
             * @var string 回應內容
             *
             * @example "smtp unreachable"
             **/
            'response_message' => $this->response_message,
            /**
             * @var int 發送狀態(0:未發送, 1:發送成功, 2:發送失敗)
             *
             * @example 1
             **/
            'status' => $this->status,
        ];
    }
}
