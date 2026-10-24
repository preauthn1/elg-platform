<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Illuminate\Support\Str;

trait TransactionTrait
{
    /**
     * @return string 32 碼
     */
    public static function getTransactionNo(bool $isMultipleWallet = false): string
    {
        $number = str_replace('-', '', Str::uuid7()->toString());
        // $logModel = $isMultipleWallet ? WalletTransactionLog::query() : TransactionLog::query();

        // $todayStart = date('Y-m-d 00:00:00');
        // $todayEnd = date('Y-m-d 23:59:59');

        // $isExist = $logModel
        //     ->whereBetween('created_at', [$todayStart, $todayEnd])
        //     ->where('transaction_no', $number)
        //     ->exists();

        return $number;
    }
}
