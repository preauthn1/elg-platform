<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddIsb extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('isb')
                ->after('ebet')
                ->nullable()
                ->comment('isb遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['isb' => 'en']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['isb' => 'chs']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['isb' => 'chs']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['isb' => 'en']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['isb' => 'id']);
    }
}