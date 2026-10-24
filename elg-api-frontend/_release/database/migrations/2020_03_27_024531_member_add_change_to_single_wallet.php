<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MemberAddChangeToSingleWallet extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('change_to_single_wallet')
                ->after('event_mode')
                ->default(0)
                ->comment('代理是否由多錢包轉換為單一錢包0:否，1:是');
        });
    }
}