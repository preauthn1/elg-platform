<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('vendor_info_games', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->index()->comment('商戶資料ID');
            $table->integer('game_id')->index()->comment('遊戲ID');
            $table->tinyInteger('join_bet_event')->default(0)->comment('是否可參加投注額活動(0:不可，1:可以)');
            $table->tinyInteger('join_win_event')->default(0)->comment('是否可參加贏分活動(0:不可，1:可以)');
            $table->tinyInteger('is_new')->default(0)->comment('是否為新遊戲(0:否,1:是)');
            $table->tinyInteger('is_hot')->default(0)->comment('是否為熱門遊戲(0:否,1:是)');
            $table->integer('sort')->default(0)->comment('排序');
            $table->decimal('valid_bet_rate', 4)->default(1)->comment('有效投注轉換比例');
            $table->tinyInteger('calc_flow_limit')->nullable()->default(1)->comment('是否計算稽核流水');
            $table->tinyInteger('status')->index()->comment('狀態(0停用，1啟用)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['vendor_info_id', 'game_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_info_games');
    }
};
