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
        SchemaMonitor::create('qqpk_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->index()->comment('商戶編號');
            $table->string('username')->unique()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('qqpk_accounts');
    }
};
