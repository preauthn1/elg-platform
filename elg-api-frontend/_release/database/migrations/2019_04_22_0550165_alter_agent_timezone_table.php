<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAgentTimezoneTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->tinyInteger('timezone_id')
                ->after('mobile')
                ->comment('時區系統編號');
            $table->tinyInteger('frontend_register')
                ->after('address')
                ->default(0)
                ->comment('前端是否可以註冊(0:不可註冊,1:可註冊)');
        });
    }
}
