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
        SchemaMonitor::create('games', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->default(0)->index()->comment('遊戲商系統編號');
            $table->integer('type_id')->index()->comment('遊戲類型');
            $table->boolean('join_bet_event')->default(false)->comment('是否可參加投注額活動，0:不可，1:可以');
            $table->boolean('join_win_event')->default(false)->comment('是否可參加贏分活動，0:不可，1:可以');
            $table->string('game_type', 191)->index()->comment('遊戲代碼');
            $table->string('name', 191)->comment('名稱');
            $table->decimal('lottery_pool', 11, 3)->nullable()->default(0)->comment('彩池金額');
            $table->string('image', 191)->nullable()->comment('遊戲圖片');
            $table->boolean('is_new')->nullable()->default(false)->comment('是否為新遊戲(0:否,1:是)');
            $table->boolean('is_hot')->nullable()->default(false)->comment('是否為熱門遊戲(0:否,1:是)');
            $table->tinyInteger('open_window')->default(0)->comment('是否另開視窗; 1:另開, 0:不另開');
            $table->tinyInteger('status')->comment('狀態：0停用，1啟用');
            $table->decimal('rtp', 5)->nullable()->default(0)->comment('RTP');
            $table->integer('sort')->nullable()->default(0)->comment('排序');
            $table->text('info')->nullable()->comment('info');
            $table->string('remark', 191)->nullable()->default('')->index()->comment('備註');
            $table->string('memo', 100)->nullable()->comment('內部人員備註用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
            $table->decimal('valid_bet_rate', 4)->nullable()->default(1)->comment('有效投注轉換比例');
            $table->tinyInteger('calc_flow_limit')->nullable()->default(1)->comment('是否計算稽核流水');

            $table->unique(['vendor_id', 'game_type', 'remark'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('games');
    }
};
