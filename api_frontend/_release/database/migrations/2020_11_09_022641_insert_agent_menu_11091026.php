<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu11091026 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'report_sr08',
            'name' => 'SR08 補點紀錄',
            'parent' => 'statistics',  //直接用parent的code
            'url' => 'report/sr08',
            'symbol' => '',
            'action' => '',
            'sort' => 55,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_management',
            'name' => '簡訊管理',
            'parent' => '0',  //直接用parent的code
            'url' => '',
            'symbol' => 'ti-email',
            'action' => '',
            'sort' => 90,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_gateway_list',
            'name' => '簡訊商',
            'parent' => 'sms_management',  //直接用parent的code
            'url' => 'sms/gateway_list',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_gateway_edit',
            'name' => '簡訊商新增/修改',
            'parent' => 'sms_management',
            'url' => 'sms/gateway_edit',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_info_list',
            'name' => '簡訊商戶資訊',
            'parent' => 'sms_management',
            'url' => 'sms/info_list',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_info_edit',
            'name' => '簡訊商戶資訊新增',
            'parent' => 'sms_management',
            'url' => 'sms/info_edit',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sms_record',
            'name' => '簡訊發送紀錄',
            'parent' => 'sms_management',
            'url' => 'sms/record',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'country_management',
            'name' => '國家管理',
            'parent' => 'system_management',
            'url' => 'country/index',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'currency_management',
            'name' => '貨幣管理',
            'parent' => 'system_management',
            'url' => 'currency/index',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'advertisement_path',
            'name' => '版位管理',
            'parent' => 'system_management',
            'url' => 'advertisementPath/index',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'advertisement',
            'name' => '廣告管理',
            'parent' => 'system_management',
            'url' => 'advertise/index',
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
        DB::table('agent_menus')->where('code', "report_sr08")->delete();
    }
}
