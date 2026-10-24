<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlertAmountToPointTransactionLog extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->renameColumn('amount', 'point');
            $table->integer('order_id')
                ->after('vendor_id')
                ->nullable()
                ->default(0)
                ->comment('訂單系統編號');
            $table->integer('vendor_id')
                ->nullable()
                ->default(0)
                ->change();
            $table->integer('vendor_balance')
                ->nullable()
                ->default(0)
                ->change();
            $table->integer('type')
                ->comment('類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值')
                ->change();
        });
    }
}
