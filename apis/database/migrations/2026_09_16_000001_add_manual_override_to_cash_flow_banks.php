<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 人工覆寫保護（2026-09-16，target/op_tickets#3174 的後續）：
// cash-flow:sync 原本無條件把 cash_flow_banks.provider_bank_code 覆寫成共用設定檔
// app/Console/Commands/CashFlow/Data/providers/*.php 的值。但三方的銀行代號可能是「按商戶帳號」發放的
// ——FPay 實證：同一支查詢接口、同一個幣別，不同商戶帳號回來的是完全不同的代號體系。
// 這種情況下，單一白標修好的代號會在下一次同步時被沖回設定檔的舊值，該站代付整條斷掉。
//
// 加兩個 nullable 便利欄當「這列被人工改過」的標記。sync 看到標記就只跳過該列的 provider_bank_code，
// 其餘欄位（bank_code、status 重新啟用）照常同步——整列跳過會讓它停在 status = 0，反而造成代付查無而斷付。
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table): void {
            $table->timestamp('manual_override_at')
                ->nullable()
                ->after('provider_bank_code')
                ->comment('人工覆寫 provider_bank_code 的時間；非 null 時 cash-flow:sync 不覆寫該欄');

            $table->string('manual_override_note', 255)
                ->nullable()
                ->after('manual_override_at')
                ->comment('人工覆寫的原因與出處，例：target/op_tickets#3174 FPay 按商戶帳號換碼');
        });

        // SchemaMonitor 靜默跳過防護：斷言欄位確實生效，否則拋錯不讓 migration 被記為完成
        foreach (['manual_override_at', 'manual_override_note'] as $column) {
            if (!Schema::hasColumn('cash_flow_banks', $column)) {
                throw new RuntimeException("cash_flow_banks.{$column} 未生效（SchemaMonitor 可能靜默跳過），中止");
            }
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table): void {
            $table->dropColumn(['manual_override_at', 'manual_override_note']);
        });
    }
};
