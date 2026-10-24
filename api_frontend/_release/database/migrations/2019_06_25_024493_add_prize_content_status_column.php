<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPrizeContentStatusColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prize_contents', function (Blueprint $table) {
            $table->boolean('status')
                ->after('percentage')
                ->default(0)
                ->comment('狀態0:停用1:使用');
        });
    }
}
