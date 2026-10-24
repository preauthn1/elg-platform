<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ip_restrictions', function (Blueprint $table) {
            $table->comment('風控IP限制（4096）：狀態啟用的IP禁止登入前台');
            $table->increments('id');
            // unique 一索引兩用：業務約束（同 IP 僅一筆，無 delete、靠 update 修改）＋登入 enforcement 的 point lookup
            $table->string('ip', 45)->unique()->comment('IP位址 (IPv4/IPv6 通用, 存 canonical form)');
            $table->string('remark', 191)->nullable()->comment('內部備註');
            $table->tinyInteger('status')->default(1)->comment('1:啟用(禁止登入) 0:關閉');
            $table->unsignedInteger('repeat_count')->default(0)->comment('被擋登入嘗試累計次數 (系統累加, 後台唯讀)');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            // 刻意不用 useCurrentOnUpdate()：repeat_count 系統累加不得蓋掉更新時間，
            // 「更新者/更新時間」保留「最後一次後台人工編輯」語意（累加走 base builder，不觸發 Eloquent timestamps）
            $table->timestamp('updated_at')->useCurrent()->comment('更新日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ip_restrictions');
    }
};
