<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgAccountsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('ag_accounts');

        Schema::create('ag_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('username')->comment('AG帳號');
            $table->string('password')->comment('AG密碼');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('AG餘額');
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

        // DB::table('ag_accounts')->insert(array(
        // 'username' => 'ag000001',
        // 'password' => '123456',
        // 'member_id' => '1',
        // 'admin' => 1
        // ));
        // DB::table('ag_accounts')->insert(array(
        // 'username' => 'ag000002',
        // 'password' => '123456',
        // 'member_id' => '2',
        // 'admin' => 1
        // ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ag_accounts');
    }
}
