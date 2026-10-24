<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddPt extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('pt')
                ->after('isb')
                ->nullable()
                ->comment('pt遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['pt' => 'en']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['pt' => 'ch']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['pt' => 'zh-cn']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['pt' => 'en']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['pt' => 'en']);
    }
}