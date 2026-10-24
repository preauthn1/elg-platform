<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropEnNameFromGame extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('games', function (Blueprint $table) {
            $table->renameColumn('en_name', 'name');
            $table->dropColumn([
                'zh_tw_name',
                'zh_cn_name'
            ]);
        });
    }
}
