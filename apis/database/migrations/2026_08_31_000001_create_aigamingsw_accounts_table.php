<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('aigamingsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('商戶編號');
            $table->string('username', 50)->unique()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 一顆通吃：綁定 Query 1 已綁定查詢、Query 2 帳號池分配（member_id=0，ORDER BY id 走
            // InnoDB 隱含 PK 尾綴）、帳號池補貨計數、客服依會員反查（member_id 領頭前綴）
            $table->index(['member_id', 'vendor_info_id', 'status'], 'idx_aigamingsw_bind_lookup');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('aigamingsw_accounts');
    }
};
