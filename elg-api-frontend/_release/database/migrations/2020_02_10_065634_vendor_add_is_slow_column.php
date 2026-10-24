<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddIsSlowColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_slow')
                ->after('only_one_game')
                ->default(0)
                ->comment('遊戲商API回應是否較慢，0:否、1:是');
        });

        DB::table('vendors')->where('id', 3)->update(['is_slow' => '1']);
    }
}