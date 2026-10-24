<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 新人專享挑戰活動設定新增「遊戲類型」限制，跟既有的 allowed_vendor_ids/bonus_flow_vendor_ids
 * 一樣是獨立多選、null=不限制；兩者一起寫進 flow_limit_restrictions（vendor_id×type_id 笛卡兒積）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('newbie_challenge_configs', 'allowed_game_type_ids')) {
                $table->json('allowed_game_type_ids')->nullable()->default(null)->after('allowed_vendor_ids')
                    ->comment('第二階段挑戰流水計算範圍的遊戲類型清單；null=不限制遊戲類型');
            }
            if (!SchemaMonitor::hasColumn('newbie_challenge_configs', 'bonus_flow_game_type_ids')) {
                $table->json('bonus_flow_game_type_ids')->nullable()->default(null)->after('bonus_flow_vendor_ids')
                    ->comment('第四階段彩金流水計算範圍的遊戲類型清單，獨立於 allowed_game_type_ids；null=不限制遊戲類型');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table) {
            foreach (['allowed_game_type_ids', 'bonus_flow_game_type_ids'] as $column) {
                if (SchemaMonitor::hasColumn('newbie_challenge_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
