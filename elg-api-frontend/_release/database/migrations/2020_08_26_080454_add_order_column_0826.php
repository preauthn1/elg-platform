<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOrderColumn0826 extends Migration
{
    protected $table_name = 'orders';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(Schema::hasTable($this->table_name)) {
            if (!Schema::hasColumn($this->table_name, 'cash_flow_id'))
            {
                Schema::table($this->table_name, function (Blueprint $table) {
                    $table->integer('cash_flow_id')
                        ->after('order_no')
                        ->nullable()
                        ->comment('支付商ID; cash_flows.id');
                });
            }
            if (!Schema::hasColumn($this->table_name, 'third_party_id'))
            {
                Schema::table($this->table_name, function (Blueprint $table) {
                    $table->string('third_party_id', 100)
                        ->after('cash_flow_id')
                        ->default('')
                        ->comment('支付商訂單號');
                });
            }
            if (!Schema::hasColumn($this->table_name, 'remark'))
            {
                Schema::table($this->table_name, function (Blueprint $table) {
                    $table->string('remark', 200)
                        ->after('status')
                        ->default('')
                        ->comment('支付訊息');
                });
            }
            if (!Schema::hasColumn($this->table_name, 'pay_time'))
            {
                Schema::table($this->table_name, function (Blueprint $table) {
                    $table->datetime('pay_time')
                        ->after('remark')
                        ->nullable()
                        ->comment('支付時間');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'cash_flow_id')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('cash_flow_id');
            });
        }
        if (Schema::hasColumn($this->table_name, 'third_party_id')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('third_party_id');
            });
        }
        if (Schema::hasColumn($this->table_name, 'remark')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('remark');
            });
        }
        if (Schema::hasColumn($this->table_name, 'pay_time')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('pay_time');
            });
        }
    }
}
