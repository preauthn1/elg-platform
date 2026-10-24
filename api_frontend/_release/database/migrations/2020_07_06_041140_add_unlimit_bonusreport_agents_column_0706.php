<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUnlimitBonusreportAgentsColumn0706 extends Migration
{
    protected $table = 'unlimit_bonusreport_agents';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->tinyinteger('correction')
                ->after('remark')
                ->default(0)
                ->comment('0:否, 1:是');
            $table->string('memo')
                ->after('correction')
                ->nullable()
                ->comment('備註');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn('correction');
            $table->dropColumn('memo');
        });
    }
}
