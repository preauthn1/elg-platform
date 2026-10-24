<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EmailGateway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailGatewayGroupResource extends JsonResource
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
             * @var int 組別ID(agent_groups.id)
             *
             * @example 141
             **/
            'id' => $this->id,
            /**
             * @var string 組別名稱
             *
             * @example "Wintoto郵件配置"
             **/
            'name' => $this->name,
        ];
    }
}
