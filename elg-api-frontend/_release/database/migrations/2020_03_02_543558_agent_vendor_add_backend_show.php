<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentVendorAddBackendShow extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_vendors', function (Blueprint $table) {
            $table->integer('backend_show')
                ->after('status')
                ->default(0)
                ->comment('後台顯示');
        });
    }
}