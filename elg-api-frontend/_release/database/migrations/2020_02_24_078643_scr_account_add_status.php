<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ScrAccountAddStatus extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('scr_accounts', function (Blueprint $table) {
            $table->integer('status')
                ->after('member_id')
                ->default(0)
                ->comment('狀態0:未取餘額，1:取得舊帳號餘額，2:轉移完成，3:剩餘點數回收完成');
            $table->string('remark')
                ->after('status')
                ->nullable()
                ->comment('備註');
        });
    }
}