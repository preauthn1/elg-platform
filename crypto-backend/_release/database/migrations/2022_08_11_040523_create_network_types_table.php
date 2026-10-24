<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNetworkTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('network_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->index()->comment('網路類型代碼');
            $table->boolean('is_smart_contract')->default(1)->comment('是否智能合約 (0 不是, 1 是');
            $table->boolean('status')->default(1)->comment('網路類型開關 (0 關, 1 開');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('network_types');
    }
}
