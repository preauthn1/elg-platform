<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu1006 extends Migration
{
    protected $table_name = 'agent_menus';
    protected $code = 'bank';
    protected $code2 = 'order_review';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable($this->table_name)) {
            if (!DB::table($this->table_name)->where('code', $this->code)->first()) {
                DB::table($this->table_name)
                    ->insert(array(
                        'code' => $this->code,
                        'name' => '銀行帳號管理',
                        'parent' => '4',
                        'url' => 'bank/index',
                        'symbol' => '',
                        'action' => '',
                        'status' => 1,
                        'sort' => 10,
                        'admin' => 0
                    ));
            }
            if (!DB::table($this->table_name)->where('code', $this->code2)->first()) {
                DB::table($this->table_name)
                    ->insert(array(
                        'code' => $this->code2,
                        'name' => '儲值訂單審核',
                        'parent' => '4',
                        'url' => 'order/index',
                        'symbol' => '',
                        'action' => '',
                        'status' => 1,
                        'sort' => 20,
                        'admin' => 0
                    ));
            }
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
            DB::table($this->table_name)->where('code', $this->code)->delete();
            DB::table($this->table_name)->where('code', $this->code2)->delete();
        }
    }
}
