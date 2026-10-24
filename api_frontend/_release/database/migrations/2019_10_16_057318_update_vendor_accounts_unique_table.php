<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateVendorAccountsUniqueTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ag_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('joker_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('haolong_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('great_wall_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('ace333_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('vplay_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
        Schema::table('xe88_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
    }
}