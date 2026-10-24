<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UserAddDomainColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('domain')
                ->after('password')
                ->nullable()
                ->comment('營運商的域名');
            $table->string('share_code')
                ->after('password')
                ->nullable()
                ->comment('營運商分享碼');
        });
    }
}