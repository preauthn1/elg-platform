<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterIgsBetRecordColumn extends Migration
{
    protected $table_name = 'igs_bet_records';

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
                $table->integer('username')->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'switch'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('switch')->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'trade_type'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('trade_type')->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'trade_sn'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('trade_sn', 50)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'remark'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('remark', 50)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'amount'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('amount', 18, 4)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'after_amount'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('after_amount', 18, 4)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'trade_at'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dateTime('trade_at')->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'utc_trade_at'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dateTime('utc_trade_at')->change();
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
