<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPgsToLanguagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            //
            $table->string('target')->nullable()->after('aes')->comment('pgs遊戲語系');
        });
        DB::table('languages')
            ->where('id', 1)
            ->update(['target' => 'en-us']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['target' => 'zh-cn']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['target' => 'zh-cn']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['target' => 'en-us']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['target' => 'en-us']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('languages', function (Blueprint $table) {
            //
            $table->dropColumn('target');
        });
    }
}
