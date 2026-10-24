<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorHourReceivedColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendor_hour_records', function (Blueprint $table) {
            $table->integer('bet_received')
                ->after('bet')
                ->default(0)
                ->comment('己兌換的投注額');
            $table->integer('win_received')
                ->after('win')
                ->default(0)
                ->comment('己兌換的贏分');
        });
    }
}