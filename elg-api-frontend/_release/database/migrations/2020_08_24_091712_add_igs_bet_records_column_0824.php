<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIgsBetRecordsColumn0824 extends Migration
{
    protected $table_name = 'igs_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        if (!Schema::hasColumn($this->table_name, 'created_at')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->timestamp('created_at')
                    ->after('utc_trade_at')
                    ->useCurrent()
                    ->comment('建立日期');
            });
        }

        if (!Schema::hasColumn($this->table_name, 'updated_at')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->timestamp('updated_at')
                    ->after('created_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
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
        if (Schema::hasColumn($this->table_name, 'created_at')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('created_at');
            });
        }

        if (Schema::hasColumn($this->table_name, 'updated_at')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
}
