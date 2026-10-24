<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMenuTargetColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_menus', function (Blueprint $table) {
            $table->string('target')
                ->after('action')
                ->nullable()
                ->comment('視窗目標');
        });

        DB::table('user_menus')->where('code', 'chat')->update(array(
            'target' => '_blank'
        ));
    }
}
