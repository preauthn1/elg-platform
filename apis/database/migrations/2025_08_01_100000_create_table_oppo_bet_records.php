<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTableOppoBetRecords extends Migration
{
    private $table_name = 'oppo_bet_records';

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
                $table->string('bet_id')->comment('注單唯一編號');
                $table->string('username', 30)->comment('玩家帳號');
                $table->string('player_id')->comment('三方帳號');
                $table->string('game_code', 30)->comment('遊戲代碼');
                $table->decimal('bet', 18, 4)->default(0.0000)->comment('下注金額');
                $table->decimal('valid_bet', 18, 4)->default(0.0000)->comment('有效投注');
                $table->decimal('win', 18, 4)->default(0.0000)->comment('派彩金額');
                $table->decimal('win_lose', 18, 4)->default(0.0000)->comment('輸贏金額');
                $table->decimal('jackpot', 18, 4)->default(0.0000)->comment('jackpot金額');
                $table->datetime('bet_time')->comment('下注時間');
                $table->datetime('payout_time')->nullable()->comment('派彩/取消/bonus時間');
                $table->tinyInteger('status')->default(0)->comment('結算狀態');
                $table->text('info')->nullable()->comment('詳細資訊');
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->unique('bet_id');
                $table->index('bet_time');
                $table->index('payout_time');
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
