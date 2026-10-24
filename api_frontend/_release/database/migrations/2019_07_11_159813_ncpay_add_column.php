<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class NcpayAddColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ncpays', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)
                ->after('amount')
                ->nullable()
                ->comment('手續費');
            $table->string('index')
                ->after('fee')
                ->nullable();
            $table->string('error')
                ->after('ncpay_status')
                ->nullable()
                ->comment('錯誤訊息');
            $table->string('code')
                ->after('error')
                ->nullable()
                ->comment('錯誤詳情');
            $table->integer('order_id')
                ->nullable()
                ->change();
            $table->integer('ncpay_deposit_id')
                ->nullable()
                ->change();
            $table->integer('ncpay_order_id')
                ->nullable()
                ->change();
            $table->string('third_party_id', 32)
                ->nullable()
                ->change();
            $table->string('bank', 16)
                ->nullable()
                ->change();
            $table->decimal('amount', 10, 2)
                ->nullable()
                ->change();
            $table->text('remark')
                ->nullable()
                ->change();
        });
    }
}
