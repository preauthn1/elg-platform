<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddPg extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('pg')
                ->after('wm')
                ->nullable()
                ->comment('pg遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['pg' => 'en_GB']); //英文
        DB::table('languages')
            ->where('id', 2)
            ->update(['pg' => 'zh_TW']); //繁中
        DB::table('languages')
            ->where('id', 3)
            ->update(['pg' => 'zh_CN']); //簡中
        DB::table('languages')
            ->where('id', 4)
            ->update(['pg' => 'ms_MY']); //馬來
        DB::table('languages')
            ->where('id', 5)
            ->update(['pg' => 'id_ID']); //印尼
    }
}
