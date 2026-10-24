<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0724 extends Migration
{
    protected $table_name = 'agent_menus';
    protected $code = 'report_sr14';
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
                        'name' => 'SR14 現金紀錄表',
                        'parent' => '11',
                        'url' => 'report/sr14',
                        'symbol' => '',
                        'action' => '',
                        'status' => 1,
                        'sort' => 140,
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
        }
    }
}
