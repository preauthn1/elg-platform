<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('dstplaysw_bet_records', function (Blueprint $table) {
            // bet_id = game_round_id，同一回合可能同時有 bet/bonus/promo 多筆交易，故不 unique；
            // reference_no = log_id，廠商保證全域唯一，作為冪等鍵
            $table->vendorBetRecordColumns(
                uniqueBetId: false,
                withReferenceNo: true,
            );

            $table->string('type', 10)->default('bet')->index()->comment('交易類型 bet:gameResult, bonus:bonusResult, promo:promoResult');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('dstplaysw_bet_records');
    }
};
