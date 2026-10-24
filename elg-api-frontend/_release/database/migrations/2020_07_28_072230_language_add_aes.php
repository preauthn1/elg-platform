<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddAes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('aes')
                ->after('ia')
                ->nullable()
                ->comment('aes遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['aes' => 'en']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['aes' => 'cn']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['aes' => 'cn']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['aes' => 'en']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['aes' => 'en']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('aes');
        });
    }
}
