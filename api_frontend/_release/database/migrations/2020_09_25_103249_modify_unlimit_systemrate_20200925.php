<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyUnlimitSystemrate20200925 extends Migration
{
    protected $table_name = 'unlimit_systemrate';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn($this->table_name, 'min_amount'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('min_amount', 18,4)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'max_amount'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('max_amount', 18,4)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'bonus_amount'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('bonus_amount', 18,4)->change();
            });
        }
        if (Schema::hasColumn($this->table_name, 'rebate_rate'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('rebate_rate', 18,4)->change();
            });
        }
        if (!Schema::hasColumn($this->table_name, 'agent_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('agent_group_id')
                    ->after('agent_id')
                    ->default(0)
                    ->comment('無限代群組ID');
            });
        }
        if (!Schema::hasColumn($this->table_name, 'created_at'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
            });
        }
        if (!Schema::hasColumn($this->table_name, 'updated_at'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
            });
        }
        if (!Schema::hasColumn($this->table_name, 'admin'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer('admin')
                    ->default(0)
                    ->comment('更新者ID');
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
        if (Schema::hasColumn($this->table_name, 'agent_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('agent_group_id');
            });
        }
    }
}
