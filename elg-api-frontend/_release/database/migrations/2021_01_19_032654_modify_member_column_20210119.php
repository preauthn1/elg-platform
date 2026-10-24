<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyMemberColumn20210119 extends Migration
{
    protected $table_name = 'members';
    protected $column_name = 'balance';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18, 4)->change();
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
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }
    }
}
