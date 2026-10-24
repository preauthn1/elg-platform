<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentDomainAddIcon extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_domains', function (Blueprint $table) {
            $table->string('icon')
                ->after('logo_mobile')
                ->nullable()
                ->comment('favorite icon');
        });
    }
}