<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\UnlimitHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string 玩家名稱 */
            'username' => $this['username'],
            /** @var float 總獎勵全民積分 */
            'totalRewardPoints' => $this['totalRewardPoints'] ?? 0.00,
            /** @var float 已提現全民積分 */
            'withdrawnPoints' => $this['withdrawnPoints'] ?? 0.00,
            /** @var float 可提現全民積分 */
            'withdrawablePoints' => $this['withdrawablePoints'] ?? 0.00,
            /** @var float 總流水 */
            'flowAmount' => $this['flowAmount'] ?? 0.00,
            /** @var float 已提現自身回水 */
            'itselfWithdrawnPoints' => $this['itselfWithdrawnPoints'] ?? 0.00,
            /** @var float 可提現自身回水 */
            'itselfWithdrawablePoints' => $this['itselfWithdrawablePoints'] ?? 0.00,
        ];
    }
}
