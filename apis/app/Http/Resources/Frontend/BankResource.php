<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use App\Helpers\Traits\Translate;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Bank */
/**
 * @knowledge rules/standard-bank-list
 */
class BankResource extends JsonResource
{
    use Translate;

    public function toArray(Request $request): array
    {
        // 使用共用 trait 的處理器，將原本的 $this->info 傳入
        $infoObject = $this->translateBankInfo($this->info ?? []);

        return [
            /** @var int 銀行id, 3級下拉選單 */
            'bank_id' => $this->id,
            /** @var string 銀行名稱 */
            'bank_name' => data_get($this, 'names.0.name', $this->name),
            /** @var string 銀行icon網址 */
            'bank_icon' => $this->image_url,
            /** @var string 銀行info */
            'bank_info' => $infoObject,
            /** @var string 銀行代碼 */
            'bank_code' => $this->code,
            /** @var int 銀行類別id, 同代付方式id */
            'bank_type' => $this->type,
            /** @var string 匯率 */
            'rate' => $this->rate,
            /** @var int 幣別id, 1級下拉選單 */
            'currency_id' => $this->currency_id ?? 0,
            /** @var string 幣別名稱 */
            'currency_name' => $this->currency->name ?? '',
            /** @var int 代付方式id, 2級下拉選單 */
            'withdrawal_type_id' => $this->type,
            /** @var string 代付方式名稱 */
            'withdrawal_type_name' => data_get($this->withdrawalType, 'names.0.name', $this->withdrawalType->name),
            /** @var string 單次提領最小值 */
            'min_amount' => (string) $this->min_amount,
            /** @var string 單次提領最大值 */
            'max_amount' => (string) $this->max_amount,
            /** @var int 手續費種類, 0=固定 1=百分比 */
            'tax_type' => $this->tax_type,
            /** @var string 手續費值 */
            'tax' => (string) $this->tax,
        ];
    }
}
