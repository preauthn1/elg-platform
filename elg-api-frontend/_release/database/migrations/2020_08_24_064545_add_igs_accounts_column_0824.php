<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIgsAccountsColumn0824 extends Migration
{
    protected $table_name = 'igs_accounts';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'game_type_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('admin')
                    ->after('updated_at')
                    ->comment('更新者ID');
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
        if (Schema::hasColumn($this->table_name, 'admin'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('admin');
            });
        }
    }
}
