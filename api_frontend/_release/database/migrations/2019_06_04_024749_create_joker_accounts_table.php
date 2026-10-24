<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateJokerAccountsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('joker_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('username')->comment('Joker帳號');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('Joker餘額');
            $table->integer('member_id')
                ->default(0)
                ->comment('會員帳號');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}
