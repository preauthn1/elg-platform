<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** @var string 流水限制 */
            'flow_limit' => $this['flow_limit'],
            /** @var string 目前流水 */
            'reach' => $this['reach'],
            /** @var int 是否通過流水限制，1:通過 0:未通過 */
            'pass' => (int) $this['pass'],
            /** @var string 錯誤訊息 */
            'message' => $this['message'],
        ];
    }
}
