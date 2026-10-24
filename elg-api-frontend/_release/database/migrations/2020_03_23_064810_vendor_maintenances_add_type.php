<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorMaintenancesAddType extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendor_maintenances', function (Blueprint $table) {
            $table->tinyInteger('type')
                ->after('vendor_id')
                ->default(1)
                ->comment('關閉類型，1:遊戲跟錢包都關閉、2:關閉遊戲、3：關閉錢包');
        });
    }
}