<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Withdrawal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 是否開啟銀行卡設定 */
            'has_bank_card_setting' => (int) $this['has_bank_card_setting'],
            /** @var int 是否綁定銀行卡 */
            'has_bank_card' => (int) $this['has_bank_card'],
            /** @var int 銀行卡數量 */
            'bank_card_amount' => (int) $this['bank_card_amount'],
            /** @var string|null 國家代碼 */
            'country_code' => $this['country_code'],
            /** @var string|null 手機號碼 */
            'mobile' => $this['mobile'],
            /** @var string 帳戶名稱 */
            'account_name' => (string) $this['account_name'],
            /** @var MemberBankAccountResource|null 帳戶列表 */
            'account' => $this['account'] ? MemberBankAccountResource::collection($this['account'])->toArray($request) : null,
            /** @var int 最小提領金額 */
            'min_amount' => (string) $this['min_amount'],
            /** @var int 最大提領金額 */
            'max_amount' => (string) $this['max_amount'],
            /** @var int 首次設定名稱 */
            'is_first' => (int) $this['is_first'],
            /** @var int 可否編輯自助洗分名字 */
            'account_name_modify' => (int) $this['account_name_modify'],
            /** @var int 有沒有未領取的充值獎勵 */
            'has_login_event_reward' => (int) $this['has_login_event_reward'],
            /** @var int 今日提領次數 */
            'withdrawal_times' => (int) $this['withdrawal_times'],
            /** @var int|null 今日提領次數上限 */
            'withdrawal_times_limit' => isset($this['withdrawal_times_limit']) ? (int) $this['withdrawal_times_limit'] : null,
            /** @var int 是否已達提領次數上限 */
            'over_times' => (int) $this['over_times'],
            /** @var int VIP稅率 */
            'vip_tax' => (string) $this['vip_tax'],
        ];
    }
}
