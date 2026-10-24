<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterTypeToGamesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('games', function (Blueprint $table) {
            $table->integer('type_id')
                ->after('vendor_id')
                ->comment('遊戲類型');
            $table->integer('lottery_pool')
                ->after('name')
                ->nullable()
                ->comment('彩池金額');
            $table->string('name')
                ->comment('名稱')
                ->change();
        });
    }
}
