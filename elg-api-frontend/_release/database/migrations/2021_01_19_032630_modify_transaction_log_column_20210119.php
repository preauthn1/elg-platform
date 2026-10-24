<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyTransactionLogColumn20210119 extends Migration
{
    protected $table_name = 'transaction_logs';
    protected $column_name = '';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->column_name = 'rate';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 4)->change();
            });
        }

        $this->column_name = 'point';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18, 4)->change();
            });
        }

        $this->column_name = 'vendor_point';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18, 4)->change();
            });
        }

        $this->column_name = 'before_balance';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18, 4)->change();
            });
        }

        $this->column_name = 'balance';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18, 4)->change();
            });
        }

        $this->column_name = 'vendor_balance';
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
        $this->column_name = 'rate';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }

        $this->column_name = 'point';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }

        $this->column_name = 'vendor_point';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }

        $this->column_name = 'before_balance';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }

        $this->column_name = 'balance';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }

        $this->column_name = 'vendor_balance';
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 10, 2)->change();
            });
        }
    }
}
