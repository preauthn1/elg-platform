<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\ThreeHierarchy;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 三層代用戶查詢列（target/tickets#4190）
 *
 * 一列＝一個返利受益人（會員或代理）。代理由排程直接派發錢包、無提領概念，
 * 其 member_username / withdraw_able / withdraw_amount 回佔位 '_'，與舊系統 API_Agent 相同。
 */
class UserSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string 身分：member=會員、agent=代理
             *
             * @example "member"
             */
            'identity' => $this->identity,
            /**
             * @var string 代理商帳號（會員列＝其所屬代理；代理列＝代理自己）
             *
             * @example "vwtest"
             */
            'parent_username' => $this->parent_username,
            /**
             * @var string 會員帳號（代理列為佔位 '_'）
             *
             * @example "vwuser001"
             */
            'member_username' => $this->member_username,
            /**
             * @var string 自身返利（代理列恆為 0）
             *
             * @example "266.0000"
             */
            'rebate_amount' => Common::numAdd('0', (string) ($this->rebate_amount ?? 0), 4),
            /**
             * @var string 下層返利
             *
             * @example "152.0000"
             */
            'down_rebate_amount' => Common::numAdd('0', (string) ($this->down_rebate_amount ?? 0), 4),
            /**
             * @var string 下下層返利
             *
             * @example "76.0000"
             */
            'down2_rebate_amount' => Common::numAdd('0', (string) ($this->down2_rebate_amount ?? 0), 4),
            /**
             * @var string 總獎金（自身＋下層＋下下層累計）
             *
             * @example "494.0000"
             */
            'bonus_amount' => Common::numAdd('0', (string) ($this->bonus_amount ?? 0), 4),
            /**
             * @var string 可提現獎金＝總獎金−已提現（代理列為佔位 '_'）
             *
             * @example "494.0000"
             */
            'withdraw_able' => $this->normalizeOrPlaceholder($this->withdraw_able),
            /**
             * @var string 已提現獎金（代理列為佔位 '_'）
             *
             * @example "0.0000"
             */
            'withdraw_amount' => $this->normalizeOrPlaceholder($this->withdraw_amount),
            /**
             * @var string 創建日期（會員列＝會員建立時間；代理列＝代理建立時間）
             *
             * @example "2026-05-28 10:00:00"
             */
            // 後台時區 GMT+8；DB 存 UTC（target/tickets#4492）
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString() : '',
        ];
    }

    /** 代理列的 withdraw 欄是佔位 '_'，只有數值才做金額正規化 */
    private function normalizeOrPlaceholder(mixed $value): string
    {
        return is_numeric($value) ? Common::numAdd('0', (string) $value, 4) : (string) $value;
    }
}
