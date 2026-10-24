<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertTimezonesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        for ($i = 12; $i > 0; $i --) {
            DB::table('timezones')->insert(array(
                'time_offset' => 'UTC-' . str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'is_summer' => 0,
                'summer_start' => '0000-01-01',
                'summer_end' => '0000-01-01',
                'status' => 1,
                'admin' => 1
            ));
        }
        DB::table('timezones')->insert(array(
            'time_offset' => 'UTC±00:00',
            'is_summer' => 0,
            'summer_start' => '0000-01-01',
            'summer_end' => '0000-01-01',
            'status' => 1,
            'admin' => 1
        ));

        for ($i = 1; $i < 15; $i ++) {
            DB::table('timezones')->insert(array(
                'time_offset' => 'UTC+' . str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'is_summer' => 0,
                'summer_start' => '0000-01-01',
                'summer_end' => '0000-01-01',
                'status' => 1,
                'admin' => 1
            ));
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Schema::dropIfExists('member_login_logs');
    }
}
