<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string 遊戲商餘額，已依 vendor 匯率換算回本地錢包幣值
             *
             * @example "1234.5600"
             */
            'vendor_balance' => $this['vendor_balance'],
            /**
             * @var string 查詢的遊戲帳號
             *
             * @example "vw_test001"
             */
            'vendor_username' => $this['vendor_username'],
        ];
    }
}
