<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class NcpayAddTimeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ncpays', function (Blueprint $table) {
            $table->integer('ncpay_updated')
                ->after('ncpay_status')
                ->default(0)
                ->comment('NCPAY更新時間');
            $table->integer('ncpay_created')
                ->after('ncpay_updated')
                ->default(0)
                ->comment('NCPAY建立時間');
        });
    }
}
