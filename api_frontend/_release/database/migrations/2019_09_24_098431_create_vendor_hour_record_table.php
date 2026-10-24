<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVendorHourRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vendor_hour_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('date_hour')->comment('時間');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('vendor_id')->comment('營運商編號');
            $table->decimal('bet', 10, 2)->comment('投注額');
            $table->decimal('win', 10, 2)->comment('勝分');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}