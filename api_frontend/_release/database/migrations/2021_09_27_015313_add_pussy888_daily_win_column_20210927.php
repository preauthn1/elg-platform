<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPussy888DailyWinColumn20210927 extends Migration
{
    protected $table_name = 'pussy888_daily_wins';
    protected $column_name = 'vendor_info_id';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('vendor_info_id')
                    ->after('id')
                    ->default(0)
                    ->comment('遊戲商設定編號');
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
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
