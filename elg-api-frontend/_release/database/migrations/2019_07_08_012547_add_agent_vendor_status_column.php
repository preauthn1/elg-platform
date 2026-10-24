<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgentVendorStatusColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_vendors', function (Blueprint $table) {
            $table->boolean('status')
                ->after('info')
                ->default(0)
                ->comment('狀態');
        });
    }
}
