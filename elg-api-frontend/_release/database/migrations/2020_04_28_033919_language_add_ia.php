<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LanguageAddIa extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('ia')
                ->after('pg')
                ->nullable()
                ->comment('ia遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['ia' => '2']); //英文
        DB::table('languages')
            ->where('id', 2)
            ->update(['ia' => '3']); //繁中
        DB::table('languages')
            ->where('id', 3)
            ->update(['ia' => '1']); //簡中
        DB::table('languages')
            ->where('id', 4)
            ->update(['ia' => '2']); //馬來(沒有所以設英文)
        DB::table('languages')
            ->where('id', 5)
            ->update(['ia' => '6']); //印尼
    }
}
