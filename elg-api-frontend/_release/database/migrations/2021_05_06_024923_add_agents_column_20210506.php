<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgentsColumn20210506 extends Migration
{
    protected $table_name = 'agents';
    protected $column_name = 'memo';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string($this->column_name)
                    ->after('last_login')
                    ->nullable()
                    ->comment('方便技術記錄用');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
