<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * NewbieChallengeService::findQualifyingDeposit() 的回傳型別：候選儲值可能來自真實訂單
 * （orders）或代理組別設置開啟時的後台開分（transaction_logs type=DEPOSIT），
 * 用 is_backend_deposit 分辨來源決定寫回 deposit_order_id 還是 deposit_transaction_log_id。
 */
class NewbieChallengeQualifyingDepositData extends Data
{
    public function __construct(
        public int $id,
        public string $point,
        public bool $is_backend_deposit,
    ) {}
}
