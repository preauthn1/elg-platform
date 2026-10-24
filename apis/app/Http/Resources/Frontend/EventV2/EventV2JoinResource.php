<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2JoinResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<float>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var null|string 活動優惠碼
             *
             * @example "A5G9WKXZ"
             **/
            'bonus_code' => $this['bonus_code'],

            /**
             * @var null|string 參加活動後導向的 URL
             *
             * @example "https://example.invalid/game/slots"
             **/
            'redirect_path' => $this['redirect_path'],

            /**
             * @var string 這次參加的獎勵金額（4 位小數字串）；紅包＝抽到的那一包（target/tickets#4234）
             *
             * @example "10.0000"
             **/
            'reward' => $this['reward'],

            /**
             * @var bool 參加即領獎：true＝獎勵已直接進主錢包；false＝進活動錢包，打流水後才領
             *
             * @example true
             **/
            'join_withdraw' => $this['join_withdraw'],
        ];
    }
}
