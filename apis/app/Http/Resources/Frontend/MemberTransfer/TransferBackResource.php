<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTransfer;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferBackResource extends JsonResource
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
             * @var string 本地錢包餘額
             *
             * @example 100
             * */
            'balance' => Common::numFloor($this['balance'], 4),

            /**
             * @var string 遊戲錢包餘額
             *
             * @example 10
             * */
            'game_wallet_balance' => Common::numFloor($this['game_wallet_balance'] ?? 0, 4),

            /**
             * @var string 禮碼, 轉帳錢包為 0
             *
             * @example 15
             * */
            'rolling_chip' => Common::numFloor($this['rolling_chip'] ?? 0, 4),

            /**
             * @var string 遊戲商餘額, 已轉換為本地錢包匯率, 非遊戲頁面為 0
             *
             * @example 100000
             * */
            'vendor_balance' => Common::numFloor($this['vendor_balance'] ?? 0, 4),
        ];
    }
}
