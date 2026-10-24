<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusReportTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'id' => 19,
            'code' => 'report_sr02',
            'name' => 'sr02開洗分明細表',
            'parent' => '11',
            'url' => 'report/sr02',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 20,
            'code' => 'report_sr03',
            'name' => 'sr03開洗分統計表',
            'parent' => '11',
            'url' => 'report/sr03',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 21,
            'code' => 'report_sr05',
            'name' => 'sr05客服轉入轉出明細表',
            'parent' => '11',
            'url' => 'report/sr05',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 22,
            'code' => 'report_sr06',
            'name' => 'sr06平台轉入轉出明細表',
            'parent' => '11',
            'url' => 'report/sr06',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 23,
            'code' => 'report_sr11',
            'name' => 'sr11遊戲平台對帳報表',
            'parent' => '11',
            'url' => 'report/sr11',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 24,
            'code' => 'report_sr12',
            'name' => 'sr12新平台下注記錄',
            'parent' => '11',
            'url' => 'report/sr12',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
    }
}