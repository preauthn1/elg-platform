<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDebugInfoLogTable extends Migration
{
    protected $table_name = 'debug_info_logs';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->string('category', 50)->nullable()->comment('分類');
                $table->longText('msg')->comment('紀錄');
                $table->string('memo_1', 500)->nullable()->comment('備註1');
                $table->string('memo_2', 500)->nullable()->comment('備註2');
                $table->bigInteger('number_value_1')->nullable()->comment('數字資訊1');
                $table->bigInteger('number_value_2')->nullable()->comment('數字資訊2');
                $table->string('user_ip', 100)->nullable()->comment('IP位址');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->string('user_identity', 50)->nullable()->comment('用戶識別資訊');
                $table->string('info_level', 50)->nullable()->comment('資訊等級');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment '主要是提供前端紀錄一些debug log資訊'");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable($this->table_name)) {
            Schema::dropIfExists($this->table_name);
        }
    }
}
