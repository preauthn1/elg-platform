<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AdAddGameTypeIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->integer('game_type_id')
                ->after('game_id')
                ->default(0)
                ->nullable(0)
                ->comment('遊戲類型編號');
        });
    }
}
