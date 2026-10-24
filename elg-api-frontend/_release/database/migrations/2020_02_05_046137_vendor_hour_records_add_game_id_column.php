<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorHourRecordsAddGameIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendor_hour_records', function (Blueprint $table) {
            $table->integer('game_id')
                ->after('vendor_id')
                ->default(0)
                ->comment('遊戲編號');
        });
    }
}