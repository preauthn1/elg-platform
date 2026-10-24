<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVendorInfosColumn20210113 extends Migration
{
    protected $table_name = 'vendor_infos';
    protected $column_name = 'rate';
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
                $table->decimal($this->column_name, 11, 5)
                    ->after('currency')
                    ->default(1)
                    ->comment('轉換率');
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
