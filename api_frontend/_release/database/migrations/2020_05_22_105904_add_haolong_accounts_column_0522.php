<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddHaolongAccountsColumn0522 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	/*
        Schema::table('haolong_accounts', function (Blueprint $table) {
            $table->string('username_modified', 30)
            	->default(DB::raw("concat('".env('HAOLONG_USER')."', username)"))
            	->after('username')
                ->comment('28win帳號含前綴');
                
            $table->index('username_modified');
        });
        */
        DB::update("ALTER TABLE haolong_accounts Add COLUMN username_modified varchar(30) AS (CONCAT('vwc',username));");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('haolong_accounts', function (Blueprint $table) {
    		$table->dropColumn('username_modified');
		});
    }
}
