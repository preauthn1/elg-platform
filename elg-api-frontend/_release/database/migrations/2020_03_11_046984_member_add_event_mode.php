<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MemberAddEventMode extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('event_mode')
                ->after('status')
                ->default(0)
                ->comment('是否進入活動鎖定模式0:未鎖定，1:鎖定');
        });
    }
}