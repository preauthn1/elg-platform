<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentMenuAddStatusColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('agent_menus', 'status')) {
            Schema::table('agent_menus', function (Blueprint $table) {
                $table->boolean('status')
                    ->after('action')
                    ->nullable()
                    ->default(0)
                    ->comment('狀態');
            });
        }

    }
}
