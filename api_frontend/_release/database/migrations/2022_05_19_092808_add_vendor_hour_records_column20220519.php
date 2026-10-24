<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVendorHourRecordsColumn20220519 extends Migration
{
    protected $table_name = 'vendor_hour_records';
    protected $column_name = 'last_bet_time';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->datetime($this->column_name)
                    ->after('win_received')
                    ->nullable()
                    ->comment('最後投注時間');
            });
        }

        $this->column_name = 'valid_bet_rate';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 4, 2)
                    ->after('last_bet_time')
                    ->default(0)
                    ->comment('有效投注倍率');
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
