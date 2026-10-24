<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfigExcludeAgentResource extends JsonResource
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
             * @var int 代理 ID
             *
             * @example 4249
             */
            'id' => $this->id,
            /**
             * @var string|null 代理名稱
             *
             * @example "frank"
             */
            'agent_username' => $this->username,
        ];
    }
}
