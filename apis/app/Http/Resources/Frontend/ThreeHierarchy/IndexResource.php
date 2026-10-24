<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string 上層玩家 */
            'topUsername' => empty($this['topUsername']) ? '' : $this['topUsername'],

            /** @var string 當前玩家 */
            'username' => $this['username'],

            /** @var int 下層一級玩家數 */
            'directPlayerCount' => $this['directPlayerCount'],

            /** @var int 下層二級玩家數 */
            'doubleDirectPlayerCount' => $this['doubleDirectPlayerCount'],

            /** @var int 今日新增玩家數 */
            'addedToday' => $this['addedToday'],

            /** @var int 今日活躍玩家數 */
            'activeToday' => $this['activeToday'],

            /** @var string 邀請碼 */
            'code' => $this['code'],

            /** @var string 連結 */
            'link' => 'https://' . $this['link'] . ((strpos($this['link'], '?') !== false) ? '' : '/Signup/') . $this['code'],
        ];
    }
}
