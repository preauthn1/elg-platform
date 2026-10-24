<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterTransactionLogTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)
                ->after('point')
                ->comment('平台點數與遊戲點數的比例');
            $table->decimal('vendor_point', 10, 2)
                ->after('point')
                ->comment('Vendor金額');
            $table->decimal('vendor_balance', 10, 2)->change();
            $table->decimal('point', 10, 2)
                ->comment('平台金額')
                ->change();
        });
    }
}
