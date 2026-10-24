<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTotalBetRecordsColumn0708 extends Migration
{

    protected $table = 'total_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table, 'bet_record_id'))
        {
            Schema::table($this->table, function (Blueprint $table)
            {
                $table->integer('bet_record_id')
                    ->after('vendor_id')
                    ->unsigned()
                    ->comment('XXX_bet_record.id');

                //unique
                $table->unique([
                    'vendor_id',
                    'bet_record_id'
                ], 'idx_total_bet_records_vendor_id_bet_record_id');
            });
        }

        if (Schema::hasColumn($this->table, 'record_time'))
        {
            Schema::table($this->table, function (Blueprint $table)
            {
                $table->dropColumn('record_time');
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
        if (Schema::hasColumn($this->table, 'bet_record_id'))
        {
            Schema::table($this->table, function (Blueprint $table)
            {
                $table->dropColumn('bet_record_id');
            });
        }
    }
}
