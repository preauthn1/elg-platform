<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人專享挑戰活動設定新增「排除遊戲商」，跟既有的 allowed_vendor_ids/bonus_flow_vendor_ids
 * 互斥（限制有值則排除欄位視為不生效，反之亦然，由 NewbieChallengeService 合併成單一有效清單）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('newbie_challenge_configs', 'excluded_vendor_ids')) {
                $table->json('excluded_vendor_ids')->nullable()->default(null)->after('allowed_game_type_ids')
                    ->comment('第二階段挑戰流水排除的遊戲商清單，與 allowed_vendor_ids 互斥；null=不排除');
            }
            if (!SchemaMonitor::hasColumn('newbie_challenge_configs', 'excluded_bonus_flow_vendor_ids')) {
                $table->json('excluded_bonus_flow_vendor_ids')->nullable()->default(null)->after('bonus_flow_game_type_ids')
                    ->comment('第四階段彩金流水排除的遊戲商清單，與 bonus_flow_vendor_ids 互斥；null=不排除');
            }
        });

        foreach (['excluded_vendor_ids', 'excluded_bonus_flow_vendor_ids'] as $column) {
            if (!Schema::hasColumn('newbie_challenge_configs', $column)) {
                throw new RuntimeException("newbie_challenge_configs.{$column} 建立失敗");
            }
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table) {
            foreach (['excluded_vendor_ids', 'excluded_bonus_flow_vendor_ids'] as $column) {
                if (SchemaMonitor::hasColumn('newbie_challenge_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
