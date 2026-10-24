<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPrizeContentPointColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prize_contents', function (Blueprint $table) {
            $table->integer('point')
                ->after('percentage')
                ->default(0)
                ->comment('平台點數');
        });
    }
}
