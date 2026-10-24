<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddRewardItemColumn20201102 extends Migration
{
    protected $table_name = 'reward_items';
    protected $column_name = 'type';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->table_name = 'reward_items';
        $this->column_name = 'type';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name)
                    ->after('level')
                    ->default(1)
                    ->comment('獎項類型; 1: 獎金, 2:獎品');
            });
        }

        $this->column_name = 'prize_name';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string($this->column_name, 50)
                    ->after('bonus')
                    ->default('')
                    ->comment('獎品名稱');
            });
        }

        $this->column_name = 'type';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name)
                    ->after('level')
                    ->default(1)
                    ->comment('獎項類型; 1: 獎金, 2:獎品');
            });
        }

        $this->table_name = 'reward_item_names';
        $this->column_name = 'prize_name';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string($this->column_name, 50)
                    ->after('name')
                    ->default('')
                    ->comment('獎項名稱');
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
        $this->table_name = 'reward_items';
        $this->column_name = 'type';
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }

        $this->column_name = 'prize_name';
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }

        $this->column_name = 'status';
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }

        $this->table_name = 'reward_item_names';
        $this->column_name = 'prize_name';
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
