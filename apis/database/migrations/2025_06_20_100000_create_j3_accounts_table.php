<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('j3_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username', 191)->unique()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('admin')->default(1);
            $table->string('password', 191)->nullable();
            $table->string('uniqueid', 191)->nullable();
            $table->string('secucode', 191)->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->foreign(['vendor_info_id'])->references(['id'])->on('vendor_infos')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('j3_accounts');
    }
};
