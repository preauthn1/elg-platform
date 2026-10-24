<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class HaolongAddVworldColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('haolong_accounts', function (Blueprint $table) {
            $table->string('is_vworld')
                ->after('admin')
                ->default(0)
                ->nullable()
                ->comment('是否為vworld舊帳號，舊帳號密碼統一');
        });
    }
}