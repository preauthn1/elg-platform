<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVpowerBetRecordColumn0616 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vpower_bet_records', function (Blueprint $table) {
            $table->string('uniqleid')
                ->after('id')
                ->unique()
                ->comment('注單編號(唯一值)');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vpower_bet_records', function (Blueprint $table) {
            $table->dropColumn('uniqleid');
        });
    }
}
