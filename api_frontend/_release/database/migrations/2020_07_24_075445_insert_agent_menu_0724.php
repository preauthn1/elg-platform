<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0724 extends Migration
{
    protected $table_name = 'agent_menus';
    protected $code = 'report_sr14';

    protected $menus = array(
        array(
            'code' => 'report_sr11',
            'name' => 'sr11遊戲平台對帳報表',
            'parent' => 'statistics',  //直接用parent的code
            'url' => 'report/newsr11',
            'symbol' => '',
            'action' => '',
            'sort' => 65,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'report_sr11_new',
            'name' => 'SR16 VJackpot',
            'parent' => 'statistics',
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => 160,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'report_sr16',
            'name' => 'SR16 VJackpot',
            'parent' => 'statistics',
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => 160,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sr16_period',
            'name' => 'SR16 period',
            'parent' => 'report_sr16',
            'url' => 'report/sr16_period',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sr16_member_search',
            'name' => 'SR16 member search',
            'parent' => 'report_sr16',
            'url' => 'report/sr16_member_search',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sr16_ticket_search',
            'name' => 'SR16 ticket search',
            'parent' => 'report_sr16',
            'url' => 'report/sr16_ticket_search',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        )


    );
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
        foreach ($this->menus as $menu) {
            DB::insert("call ADMIN_insert_agent_menus(" .
                "'" . $menu['code'] . "', " .
                "'" . $menu['name'] . "', " .
                "'" . $menu['parent'] . "', " .
                "'" . $menu['url'] . "', " .
                "'" . $menu['symbol'] . "', " .
                "'" . $menu['action'] . "', " .
                "" . $menu['status'] . ", " .
                "" . $menu['sort'] . ", " .
                "" . $menu['admin'] .
                ")");
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
