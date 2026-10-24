<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PrizeContentAddSentColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prize_contents', function (Blueprint $table) {
            $table->integer('sent')
                ->after('number')
                ->nullable()
                ->default(0)
                ->comment('已送出');
        });
    }
}
