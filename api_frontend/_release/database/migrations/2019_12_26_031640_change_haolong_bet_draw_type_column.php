<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeHaolongBetDrawTypeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('haolong_bet_records', function ($table) {
            $table->integer('draw_type')->comment('開彩莊家{0:全部, 1:Magnum, 2: PMP, 3: Toto, 4: Singapore, 5: Sabah, 6: Sandakan, 7: Sarawak, 8: GD (豪龙)}')->change();
            $table->index('login_id');
            $table->index('draw_date');
        });
    }
}