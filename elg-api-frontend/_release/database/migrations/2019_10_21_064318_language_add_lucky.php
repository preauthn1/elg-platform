<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddLucky extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('lucky')
                ->after('ag')
                ->nullable()
                ->comment('Lucky語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['lucky' => 'en']);

        DB::table('languages')
            ->whereIn('id', [2, 3])
            ->update(['lucky' => 'cn']);
    }
}