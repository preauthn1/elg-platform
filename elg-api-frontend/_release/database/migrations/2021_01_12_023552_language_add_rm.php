<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddRm extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('rm')
                ->after('dg')
                ->nullable()
                ->comment('rm遊戲語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['rm' => 'en']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['rm' => 'zh']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['rm' => 'zh']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['rm' => 'en']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['rm' => 'en']);
        DB::table('languages')
            ->where('id', 6)
            ->update(['rm' => 'en']);
        DB::table('languages')
            ->where('id', 7)
            ->update(['rm' => 'en']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('rm');
        });
    }
}
