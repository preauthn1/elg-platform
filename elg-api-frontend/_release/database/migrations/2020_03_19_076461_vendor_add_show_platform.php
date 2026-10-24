<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddShowPlatform extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('show_on_pc')
                ->after('event_lock')
                ->default(1)
                ->comment('PC版是否顯示0:不顯示，1:顯示');
            $table->boolean('show_on_mobile')
                ->after('event_lock')
                ->default(1)
                ->comment('Mobile版是否顯示0:不顯示，1:顯示');
        });

        DB::table('vendors')->where('id', 18)->update(array(
            'show_on_pc' => 0
        ));
    }
}