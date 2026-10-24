<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddWm extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('wm')
                ->after('ygg')
                ->nullable()
                ->comment('wm遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['wm' => 'EN']); //英文
        DB::table('languages')
            ->where('id', 2)
            ->update(['wm' => 'ZH']); //繁中
        DB::table('languages')
            ->where('id', 3)
            ->update(['wm' => 'ZH-HANT']); //簡中
        DB::table('languages')
            ->where('id', 4)
            ->update(['wm' => 'EN']); //馬來
        DB::table('languages')
            ->where('id', 5)
            ->update(['wm' => 'ID']); //印尼
    }
}