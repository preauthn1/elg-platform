<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeMessageTypeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('messages', function ($table) {
            $table->integer('type')->comment('類型1:文字,2:圖片,3:紅包,4:聲音,5:影片,6:洗分,7:開分,8:開分贈點')->change();
        });
    }
}