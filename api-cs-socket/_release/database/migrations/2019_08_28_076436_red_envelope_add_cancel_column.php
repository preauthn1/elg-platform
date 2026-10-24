<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RedEnvelopeAddCancelColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('red_envelopes', function (Blueprint $table) {
            $table->dateTime('cancel_time')
                ->after('status')
                ->comment('指定系統自動取消時間');
        });
    }
}