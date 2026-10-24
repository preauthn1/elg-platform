<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddEbet extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('ebet')
                ->after('lucky')
                ->nullable()
                ->comment('ebet語系');
        });

        DB::table('languages')
            ->where('id', 1)
            ->update(['ebet' => 'en_us']);
        DB::table('languages')
            ->where('id', 2)
            ->update(['ebet' => 'zh_tw']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['ebet' => 'zh_cn']);
        DB::table('languages')
            ->where('id', 4)
            ->update(['ebet' => 'ms_my']);
        DB::table('languages')
            ->where('id', 5)
            ->update(['ebet' => 'in_id']);
    }
}