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
        SchemaMonitor::create('vendors', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('name', 50)->unique()->comment('遊戲商名稱');
            $table->string('code', 32)->comment('遊戲商表格前綴');
            $table->string('tname', 32)->nullable()->comment('agent後台會用tname來找對應的xxx_accounts');
            $table->string('third_party_code', 20)->nullable()->comment('三方代碼; 如果是聚合遊戲商, 就用同一個代碼');
            $table->decimal('rate', 11, 5)->comment('平台點數與遊戲點數的比例');
            $table->decimal('percentage', 11, 5)->comment('拆帳比例');
            $table->string('image', 191)->default('0')->comment('遊戲商圖片');
            $table->string('app_icon', 191)->nullable()->comment('遊戲商app的圖示');
            $table->text('info')->comment('API使用資訊');
            $table->boolean('is_new')->default(false)->comment('是否顯示新遊戲商標籤0:不顯示,1:顯示');
            $table->boolean('is_hot')->default(false)->comment('是否顯示熱門遊戲商標籤0:不顯示,1:顯示');
            $table->boolean('need_login')->default(false)->comment('是否需另外登入0:不需要1:需要');
            $table->boolean('only_one_game')->nullable()->default(false)->comment('只有一款遊戲');
            $table->tinyInteger('is_single_wallet')->nullable()->default(0)->comment('是否為單一錢包遊戲');
            $table->boolean('is_slow')->default(false)->comment('遊戲商API回應是否較慢，0:否、1:是');
            $table->boolean('event_lock')->default(false)->comment('是否可以參加鎖分活動0:不可，1:可以');
            $table->boolean('show_on_mobile')->default(true)->comment('Mobile版是否顯示0:不顯示，1:顯示');
            $table->boolean('show_on_pc')->default(true)->comment('PC版是否顯示0:不顯示，1:顯示');
            $table->tinyInteger('show_on_app')->default(0)->comment('APP版是否顯示 0:不顯示，1顯示');
            $table->tinyInteger('open_window')->default(0)->comment('是否另開視窗; 0:不另開, 1:另開');
            $table->tinyInteger('summary_daily')->default(1)->comment('summary時間; 0:不計算, 1:每小時, 2:每天');
            $table->text('whitelist')->nullable()->comment('白名單; ip1,ip2,ip3,ip4,...');
            $table->decimal('valid_bet_rate', 4)->default(1)->index()->comment('有效投注轉換比例');
            $table->tinyInteger('calc_flow_limit')->nullable()->default(1)->comment('是否計算稽核流水');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->text('memo')->nullable();
            $table->tinyInteger('calc_time_zone')->default(8)->comment('後台跟三方後台的計算時區');
            $table->boolean('unbind')->default(false)->comment('是否可以解綁; 0:否, 1:是');
            $table->integer('sort')->nullable()->comment('排序');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:關閉,1:正常');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendors');
    }
};
