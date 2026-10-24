<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddNewHotColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_new')
                ->after('info')
                ->default(0)
                ->comment('是否顯示新遊戲商標籤0:不顯示,1:顯示');
            $table->boolean('is_hot')
                ->after('is_new')
                ->default(0)
                ->comment('是否顯示熱門遊戲商標籤0:不顯示,1:顯示');
        });
    }
}