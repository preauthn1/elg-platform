<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableVendorInfoGames extends Migration
{
    protected $table_name = 'vendor_info_games';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('vendor_info_id')->comment('商戶資料ID');
                $table->integer('game_id')->comment('遊戲ID');
                $table->tinyInteger('join_bet_event')->default('0')->comment('是否可參加投注額活動(0:不可，1:可以)');
                $table->tinyInteger('join_win_event')->default('0')->comment('是否可參加贏分活動(0:不可，1:可以)');
                $table->tinyInteger('is_new')->default('0')->comment('是否為新遊戲(0:否,1:是)');
                $table->tinyInteger('is_hot')->default('0')->comment('是否為熱門遊戲(0:否,1:是)');
                $table->integer('sort')->default('0')->comment('排序');
                $table->decimal('valid_bet_rate')->default('1.00')->comment('有效投注轉換比例');
                $table->tinyInteger('status')->comment('狀態(0停用，1啟用)');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}
