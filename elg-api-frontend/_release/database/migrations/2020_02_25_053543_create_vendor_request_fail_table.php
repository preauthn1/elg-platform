<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVendorRequestFailTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vendor_request_fails', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->integer('status')->default(0)->comment('狀態，0：停止轉帳(未簡訊通知)、1:停止轉帳(已簡訊通知)、2:恢復轉帳');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });

        Schema::table('vendor_request_fails', function (Blueprint $table) {
            $table->index('vendor_id');
            $table->index('status');
        });
    }
}