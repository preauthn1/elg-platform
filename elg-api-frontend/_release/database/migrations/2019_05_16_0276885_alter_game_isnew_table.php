<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterGameIsnewTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_new')
                ->nullable()
                ->default(0)
                ->after('image')
                ->comment('是否為新遊戲(0:否,1:是)');
            $table->boolean('is_hot')
                ->nullable()
                ->default(0)
                ->after('is_new')
                ->comment('是否為熱門遊戲(0:否,1:是)');
        });
    }
}
