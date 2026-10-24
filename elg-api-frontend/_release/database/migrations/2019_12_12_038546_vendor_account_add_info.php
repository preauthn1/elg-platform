<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAccountAddInfo extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //AG
        Schema::table('ag_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //joker
        Schema::table('joker_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //918
        Schema::table('scr_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //4d
        Schema::table('haolong_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //GW99
        Schema::table('great_wall_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //ace333
        Schema::table('ace333_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //vplay
        Schema::table('vplay_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //鬥雞
        Schema::table('coke_fighting_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //MEGA888
        Schema::table('mega888_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //xe88
        Schema::table('xe88_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //lucky
        Schema::table('lucky_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });

        //ebet
        Schema::table('ebet_accounts', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商資訊編號');
        });
    }
}