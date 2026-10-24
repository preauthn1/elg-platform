<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Support\Facades\DB;

/**
 * 會員詳情抽屜——三張圓餅圖（4274，自 API_Agent report/deposit、report/transaction 移植）。
 *
 * 時間窗固定「近 90 天」（工單定案，與統計摘要的期間篩選各自獨立）；
 * 轉點兩張取 TOP 10 遊戲商＋其餘合併「其他」（PM 定案，總額才對得上）；
 * 舊版的 Redis 快取刻意不移植（後台不用快取原則）。
 */
class MemberDetailChartsService
{
    use ChecksMemberScope;

    private const WINDOW_DAYS = 90;

    private const TRANSFER_TOP_N = 10;

    /**
     * @return array<string, mixed>
     */
    public function charts(Agent $agent, int $memberId): array
    {
        $this->findMemberInScope($agent, $memberId);

        $since = now()->subDays(self::WINDOW_DAYS)->toDateTimeString();

        return [
            'window_days' => self::WINDOW_DAYS,
            'deposit_methods' => $this->depositMethods($memberId, $since),
            'transfer_in' => $this->transferByVendor($memberId, TransactionEnum::TO_VENDOR->value, $since),
            'transfer_out' => $this->transferByVendor($memberId, TransactionEnum::FROM_VENDOR->value, $since),
        ];
    }

    /**
     * 儲值方式分布：orders 成功單依支付方式（order_type）分組。
     *
     * @return array<int, array<string, mixed>>
     */
    private function depositMethods(int $memberId, string $since): array
    {
        return DB::table('orders as o')
            ->leftJoin('order_types as ot', 'ot.id', '=', 'o.order_type_id')
            ->where('o.member_id', $memberId)
            // 成功單＝1（成功，過渡態）＋3（已上分，最終態）。TPower 實查 status=1 僅 52 筆、
            // status=3 有 948 萬筆——舊版 report/deposit 只查 1 等於永遠只畫過渡中的單，不移植該行為
            ->whereIn('o.status', [1, 3])
            ->where('o.created_at', '>=', $since)
            ->groupBy('o.order_type_id', 'ot.name')
            ->selectRaw('o.order_type_id, ot.name, SUM(o.amount) as total')
            ->orderByDesc(DB::raw('SUM(o.amount)'))
            ->get()
            ->map(fn (object $row) => [
                'order_type_id' => (int) $row->order_type_id,
                'name' => (string) ($row->name ?? ''),
                'total' => Common::numAdd('0', (string) $row->total, 4),
            ])
            ->all();
    }

    /**
     * 轉點分布（轉入/轉出）：transaction_logs 成功紀錄依遊戲商分組，
     * TOP 10 之外合併為 others_total（前端渲染「其他」一片）。
     *
     * @return array<string, mixed>
     */
    private function transferByVendor(int $memberId, int $type, string $since): array
    {
        $rows = DB::table('transaction_logs as t')
            ->leftJoin('vendors as v', 'v.id', '=', 't.vendor_id')
            ->where('t.member_id', $memberId)
            ->where('t.type', $type)
            ->where('t.status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where('t.created_at', '>=', $since)
            ->groupBy('t.vendor_id', 'v.name')
            ->selectRaw('t.vendor_id, v.name, SUM(t.point) as total')
            ->orderByDesc(DB::raw('SUM(t.point)'))
            ->get();

        $top = $rows->take(self::TRANSFER_TOP_N)
            ->map(fn (object $row) => [
                'vendor_id' => (int) $row->vendor_id,
                'name' => (string) ($row->name ?? ''),
                'total' => Common::numAdd('0', (string) $row->total, 4),
            ])
            ->values()
            ->all();

        $others = $rows->slice(self::TRANSFER_TOP_N)
            ->reduce(fn (string $carry, object $row) => Common::numAdd($carry, (string) $row->total, 4), '0.0000');

        return [
            'items' => $top,
            'others_total' => $others,
        ];
    }
}
