<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddYgg extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('ygg')
                ->after('ebet')
                ->nullable()
                ->comment('ygg遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['ygg' => 'en']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['ygg' => 'zh_hant']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['ygg' => 'zh_hans']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['ygg' => 'en']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['ygg' => 'id']);
    }
}