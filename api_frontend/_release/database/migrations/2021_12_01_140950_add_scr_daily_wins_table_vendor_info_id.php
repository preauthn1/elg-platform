<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddScrDailyWinsTableVendorInfoId extends Migration
{
    protected $table_name = 'scr_daily_wins';
    protected $column_name = 'vendor_info_id';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name)
                    ->after('id')
                    ->default(0)
                    ->index()
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
        //
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropIndex('scr_daily_wins_vendor_info_id_index');
                $table->dropColumn($this->column_name);
            });
        }
    }
}
