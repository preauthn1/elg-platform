<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('slot4dsw_accounts', function (Blueprint $table) {
            // 標準遊戲商帳號欄位（id / vendor_info_id / username / member_id / admin / status / timeColumns
            // + idx_slot4dsw_bind_lookup + idx_slot4dsw_pool_pick），username 對齊 api.md 放寬至 50
            $table->vendorAccountColumns(usernameLength: 50);

            // Slot4D 專屬：欄名為 auth_token（非 session_token），故不開 withSessionToken，手動宣告
            $table->string('auth_token', 200)->nullable()->comment('登入遊戲更新（GameLogin 產生，供回調驗證玩家會話）');
        });

        SchemaMonitor::create('slot4dsw_bet_records', function (Blueprint $table) {
            // 標準注單欄位（id / vendor_info_id / member_id / username / bet_id / reference_no / game_code /
            // bet / valid_bet / win / win_lose / bonus / jackpot / rolling_chip / bet_time / payout_time /
            // status / info / timeColumns）。
            // uniqueBetId: false → bet_id 走一般 index，唯一性改由下方 type+bet_id+payout_time 複合鍵保證
            // （同一 bet_id 於 N/B/J 與不同派彩時間可多列）。reference_no 仍為 unique（withReferenceNo 預設 true）。
            $table->vendorBetRecordColumns(uniqueBetId: false);

            // Slot4D 專屬欄位
            $table->string('type', 1)->default('N')->index()->comment('N:normal, B:bonus, J:jackpot');
            $table->string('round_id', 50)->nullable()->index()->comment('遊戲回合Id（RoundId）');

            // 冪等複合鍵：同 bet_id 於不同 type / payout_time 允許多列，整組唯一
            $table->unique(['type', 'bet_id', 'payout_time'], 'unique_type_bet_payout');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('slot4dsw_bet_records');
        SchemaMonitor::dropIfExists('slot4dsw_accounts');
    }
};
