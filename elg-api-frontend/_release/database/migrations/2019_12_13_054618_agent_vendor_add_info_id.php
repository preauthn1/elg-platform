<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentVendorAddInfoId extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_vendors', function (Blueprint $table) {
            $table->integer('vendor_info_id')
                ->after('vendor_id')
                ->default(0)
                ->comment('遊戲商資訊編號');
            $table->dropColumn(['info']);
        });
    }
}