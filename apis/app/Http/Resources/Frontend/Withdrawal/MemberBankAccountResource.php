<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Withdrawal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberBankAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 銀行ID */
            'bank_id' => $this['bank_id'],
            /** @var string 銀行名稱 */
            'bank_name' => $this['bank_name'],
            /** @var string 銀行代碼 */
            'bank_code' => $this['bank_code'],
            /** @var int 銀行類型 */
            'bank_type' => $this['bank_type'],
            /** @var string 銀行費率 */
            'bank_rate' => $this['bank_rate'],
            /** @var string 最小提領金額 */
            'min_amount' => $this['min_amount'],
            /** @var string 最大提領金額 */
            'max_amount' => $this['max_amount'],
            /** @var int 稅率類型 */
            'tax_type' => $this['tax_type'],
            /** @var string 銀行稅率 */
            'bank_tax' => $this['bank_tax'],
            /** @var string 帳戶名稱 */
            'account_name' => $this['account_name'],
            /** @var string 帳戶號碼 */
            'account_no' => $this['account_no'],
        ];
    }
}
