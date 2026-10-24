<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * transaction_logs 的選填欄位。
 *
 * 對應 decimal 欄位的成員（tax / vendor_point / rate / vendor_balance）一律宣告為 numeric string，
 * 不可用 int：金額型參數的來源多半是外部輸入或運算結果（例如 CS 後台的紅包稅是
 * `strval(red_envelopes_tax * amount)` 算出的字串，可能帶小數），宣告成 int 只有兩種下場——
 * 呼叫端補 `(int)` 轉型而截斷小數（餘額扣足額、log 少記，對帳對不上），
 * 或在 `strict_types` 下收到字串／浮點數直接 TypeError。
 *
 * 傳入前一律以 `Common::num*` 正規化（如 `Common::numAdd('0', (string) $value, 4)`）。
 * vendor_id / order_id / admin 對應的是 integer 欄位，維持 int 為正確型別。
 */
class TransactionLogOptionalData extends Data
{
    public function __construct(
        public int $vendor_id = 0,
        public int $order_id = 0,
        public string $tax = '0',
        public string $vendor_point = '0',
        public string $rate = '0',
        public string $vendor_balance = '0',
        public string $remark = '',
        public string $checksum = '',
        public string $result = '',
        public string $operator = '',
        public int $admin = 0,
    ) {}
}
