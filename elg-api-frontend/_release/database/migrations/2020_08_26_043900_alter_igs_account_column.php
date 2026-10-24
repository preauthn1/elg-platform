<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterIgsAccountColumn extends Migration
{
    protected $table_name = 'igs_accounts';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn($this->table_name, 'username'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('username', 50)->change();
            });
        }

        if (Schema::hasColumn($this->table_name, 'player_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('player_id')->change();
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
        //
    }
}
