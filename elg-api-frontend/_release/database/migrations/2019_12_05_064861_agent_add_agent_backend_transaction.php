<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentAddAgentBackendTransaction extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('agent_backend_transaction')
                ->after('transaction_all')
                ->default(0)
                ->comment('是否可以在代理後台開洗分，0:否1:是');
            $table->decimal('balance', 13, 2)
                ->after('nickname')
                ->default(0)
                ->comment('餘額');
        });
    }
}