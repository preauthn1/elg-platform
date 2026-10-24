<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOrderImageColumn0925 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('cash_flows', 'type'))
        {
            Schema::table('cash_flows', function (Blueprint $table) {
                $table->integer('type')
                    ->after('info')
                    ->comment('支付類別(1.線上支付、2線下支付)');
            });
        }

        if (!Schema::hasColumn('order_images', 'bank_id'))
        {
            Schema::table('order_images', function (Blueprint $table) {
                $table->integer('bank_id')
                    ->after('order_id')
                    ->comment('銀行帳戶id');
            });
        }

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('cash_flows', 'type'))
        {
            Schema::table('cash_flows', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }

        if (Schema::hasColumn('order_images', 'bank_id'))
        {
            Schema::table('order_images', function (Blueprint $table) {
                $table->dropColumn('bank_id');
            });
        }
    }
}
