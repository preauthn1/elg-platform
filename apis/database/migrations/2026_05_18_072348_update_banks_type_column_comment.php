<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * banks.type 欄位註解修正
 *
 * 將 type=2 的描述從「手機號碼」更新為「電子錢包」，與目前實際語意對齊：
 * 電子錢包涵蓋手機號碼、錢包 ID、QR Code 等多種轉帳依據，
 * 原「手機號碼」描述過於侷限。
 *
 * 對應 enum：App\Enums\WithdrawalTypeEnum（GCASH=2 沿用舊名，不在本次重命名範圍）
 */
return new class extends Migration
{
    private const string NEW_COMMENT = '類型; 1:銀行號碼, 2:電子錢包, 3:加密貨幣, 4:外幣銀行, 5:未知, 6:澳洲銀行, 7:澳洲PayId';

    private const string OLD_COMMENT = '類型; 1:銀行號碼, 2:手機號碼, 3:加密貨幣, 4:外幣銀行, 5:未知, 6:澳洲銀行, 7:澳洲PayId';

    public function up(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            $table->tinyInteger('type')
                ->default(1)
                ->comment(self::NEW_COMMENT)
                ->change();
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            $table->tinyInteger('type')
                ->default(1)
                ->comment(self::OLD_COMMENT)
                ->change();
        });
    }
};
