<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddSbo extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('sbo')
                ->after('pt')
                ->nullable()
                ->comment('sbo遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['sbo' => 'en-US']); //英文
        DB::table('languages')
            ->where('id', 2)
            ->update(['sbo' => 'zh-CN']); //繁中
        DB::table('languages')
            ->where('id', 3)
            ->update(['sbo' => 'zh-CN']); //簡中
        DB::table('languages')
            ->where('id', 4)
            ->update(['sbo' => 'en-US']); //馬來
        DB::table('languages')
            ->where('id', 5)
            ->update(['sbo' => 'ID']); //印尼
    }
}