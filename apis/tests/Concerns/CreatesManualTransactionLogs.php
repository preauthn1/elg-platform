<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Transaction\TransactionStatusEnum;
use Illuminate\Support\Facades\DB;

/**
 * 直接寫入 transaction_logs 供測試用（專案沒有 TransactionLog factory）。
 * 預設模擬客服／代理的手動開分洗分：order_id = 0、status = 1000。
 */
trait CreatesManualTransactionLogs
{
    protected function makeManualTransaction(
        int $memberId,
        int $type,
        string $point,
        string $createdAt,
        int $orderId = 0,
        int $status = TransactionStatusEnum::STATUS_SUCCESS->value,
    ): int {
        return (int) DB::table('transaction_logs')->insertGetId([
            'transaction_no' => str_replace('-', '', (string) \Illuminate\Support\Str::uuid()),
            'agent_id' => 1,
            'member_id' => $memberId,
            'vendor_id' => 0,
            'order_id' => $orderId,
            'type' => $type,
            'point' => $point,
            'tax' => '0',
            'vendor_point' => '0',
            'rate' => '1',
            'before_balance' => '0',
            'balance' => $point,
            'vendor_balance' => '0',
            'status' => $status,
            'remark' => 'test manual transaction',
            'result' => '',
            'operator' => 'cs_tester',
            'platform' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'admin' => 0,
        ]);
    }
}
