<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddXe88 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('xe88')
                ->after('ag')
                ->nullable()
                ->comment('xe88遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['xe88' => 'EN']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['xe88' => 'CN']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['xe88' => 'CN']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['xe88' => 'EN']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['xe88' => 'EN']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('xe88');
        });
    }
}
