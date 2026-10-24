<?php

namespace App\Http\Resources\Ton\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Order
 */
class QueryDepositOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $request->getSchemeAndHttpHost();

        return [
            /** @var string 訂單編號 */
            'order_no' => $this->order_no,
            /** @var string 商家訂單編號 */
            'merchant_order_no' => $this->merchant_order_no,
            /** @var string 金額 */
            'amount' => $this->amount,
            /** @var int 狀態 */
            'status' => $this->status,
            /** @var string|null 上鏈時間 */
            'confirmed_at' => $this->confirmed_at ? Carbon::parse($this->confirmed_at)->toIso8601ZuluString() : null,
            /** @var string 支付網址 */
            'payment_url' => $domain . '/cashier/' . $this->order_no, // TODO: 可能可以改成從 env 取得網域
        ];
    }
}
