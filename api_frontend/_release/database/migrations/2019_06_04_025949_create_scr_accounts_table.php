<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateScrAccountsTable extends Migration
{
    protected $table_name = 'scr_accounts';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊編號');
            $table->string('username')->comment('scr帳號');
            $table->string('player_id')->nullable()->comment('918玩家帳號');
            $table->string('password', 20)->nullable()->comment('密碼');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('scr餘額');
            $table->integer('vendor_id')
                ->default(0)
                ->comment('營運商編號');
            $table->integer('member_id')
                ->default(0)
                ->comment('會員帳號');
            $table->integer('status')->default(0)->comment('狀態0:未取餘額，1:取得舊帳號餘額，2:轉移完成，3:剩餘點數回收完成');
            $table->string('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique('username');
        });
    }
}
