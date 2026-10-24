<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertParamEventTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('params')->insert(array(
            'type' => 'event_type',
            'parameter' => 'mission',
            'value' => 1,
            'sort' => 0,
            'status' => 1,
            'remark' => '任務'
        ));
        DB::table('params')->insert(array(
            'type' => 'event_type',
            'parameter' => 'activity',
            'value' => 2,
            'sort' => 0,
            'status' => 1,
            'remark' => '活動'
        ));

        DB::table('params')->insert(array(
            'type' => 'event_action',
            'parameter' => 'bet',
            'value' => 1,
            'sort' => 0,
            'status' => 1,
            'remark' => '下注'
        ));
        DB::table('params')->insert(array(
            'type' => 'event_action',
            'parameter' => 'deposit',
            'value' => 2,
            'sort' => 0,
            'status' => 1,
            'remark' => '儲值'
        ));
        DB::table('params')->insert(array(
            'type' => 'event_action',
            'parameter' => 'transaction',
            'value' => 3,
            'sort' => 0,
            'status' => 1,
            'remark' => '轉點'
        ));
        DB::table('params')->insert(array(
            'type' => 'transaction_type',
            'parameter' => 'to_ag',
            'value' => 1,
            'sort' => 0,
            'status' => 1,
            'remark' => '平台轉入遊戲商'
        ));
        DB::table('params')->insert(array(
            'type' => 'transaction_type',
            'parameter' => 'from_ag',
            'value' => 2,
            'sort' => 0,
            'status' => 1,
            'remark' => '遊戲商轉入平台'
        ));
        DB::table('params')->insert(array(
            'type' => 'transaction_type',
            'parameter' => 'deposit',
            'value' => 3,
            'sort' => 0,
            'status' => 1,
            'remark' => '訂單儲值'
        ));
    }
}
