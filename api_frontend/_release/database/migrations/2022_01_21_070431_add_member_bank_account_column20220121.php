<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMemberBankAccountColumn20220121 extends Migration
{
    protected $table_name = 'member_bank_accounts';
    protected $column_name_1 = 'status';
    protected $column_name_2 = 'sort';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name_1)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->boolean($this->column_name_1)
                    ->after('account_no')
                    ->default(0)
                    ->comment('狀態');
            });
        }

        if (!Schema::hasColumn($this->table_name, $this->column_name_2)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_2)
                    ->after($this->column_name_1)
                    ->default(0)
                    ->comment('排序');
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
        if (Schema::hasColumn($this->table_name, $this->column_name_1)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_1);
            });
        }

        if (Schema::hasColumn($this->table_name, $this->column_name_2)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_2);
            });
        }
    }
}
