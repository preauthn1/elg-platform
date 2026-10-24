<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use App\Enums\Transaction\WalletTransactionEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionListResource extends JsonResource
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
             * @var string 代理名稱
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var string 會員名稱
             *
             * @example "zoe102"
             **/
            'member_username' => $this->member_username,
            /**
             * @var string|null 遊戲商名稱
             *
             * @example "VPLUS"
             **/
            'vendor_name' => $this->vendor_name,
            /**
             * @var float 異動金額
             *
             * @example 200
             **/
            'point' => (float) match ($this->type) {
                WalletTransactionEnum::EVENT_WALLET_JOIN->value => $this->point,
                WalletTransactionEnum::EVENT_WALLET_TO_VENDOR->value => ($this->point * -1),
                WalletTransactionEnum::EVENT_WALLET_FROM_VENDOR->value => $this->point,
                WalletTransactionEnum::EVENT_WALLET_INIT->value => ($this->point * -1),
                WalletTransactionEnum::EVENT_V2_OPERATOR_CHANGE->value => $this->point,
                WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value => ($this->point * -1),
                default => $this->point,
            },
            /**
             * @var float 異動前金額
             *
             * @example 200
             **/
            'before_balance' => (float) $this->before_balance,
            /**
             * @var float 異動後金額
             *
             * @example 200
             **/
            'balance' => (float) $this->balance,
            /**
             * @var string 交易時間
             *
             * @example "2025-05-10 12:19:16"
             **/
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
            /**
             * @var int 交易類型(11:活動錢包增加獎金, 12:活動錢包轉入遊戲商, 13:遊戲商轉入遊活動包, 14:活動錢包重置歸零,15:後台活動錢包操作異動)
             *
             * @example 11
             **/
            'type' => $this->type,
            /**
             * @var string 備註
             *
             * @example "E2vnlfc88800000015(59-569)"
             **/
            'remark' => $this->account_username . '(' . $this->event_v2_detail_id . '-' . $this->event_v2_user_join_id . ')',
            /**
             * @var string|null 操作代理
             *
             * @example "Leo"
             **/
            'operate_agent_username' => $this->operate_agent_username,
            /**
             * @var int 玩家參加狀態
             *
             * @example 1
             **/
            'user_join_status' => $this->user_join_status,
            /**
             * @var int 狀態(1000:成功)
             *
             * @example 1000
             **/
            'status' => $this->status,
        ];
    }
}
