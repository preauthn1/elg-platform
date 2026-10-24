<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSummaryBetRecordColumn200820 extends Migration
{
    protected $table_name = 'summary_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'v_jackpot'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('v_jackpot', 18,2)
                    ->after('share_range')
                    ->default(0.00)
                    ->comment('v_jackpot');
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
        if (Schema::hasColumn($this->table_name, 'v_jackpot'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('v_jackpot');
            });
        }
    }
}
