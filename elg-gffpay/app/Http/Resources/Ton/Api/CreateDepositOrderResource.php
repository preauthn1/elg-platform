<?php

namespace App\Http\Resources\Ton\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class CreateDepositOrderResource extends JsonResource
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
            /** @var string 支付網址 */
            'payment_url' => $domain . '/cashier/' . $this->order_no, // TODO: 可能可以改成從 env 取得網域
        ];
    }
}
