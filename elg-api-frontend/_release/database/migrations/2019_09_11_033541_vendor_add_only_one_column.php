<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddOnlyOneColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('only_one_game')
                ->after('need_login')
                ->nullable()
                ->default(0)
                ->comment('只有一款遊戲');
        });

        DB::table('vendors')->where('id', 4)->update(array(
            'only_one_game' => 1
        ));
        DB::table('vendors')->where('id', 9)->update(array(
            'only_one_game' => 1
        ));
    }
}