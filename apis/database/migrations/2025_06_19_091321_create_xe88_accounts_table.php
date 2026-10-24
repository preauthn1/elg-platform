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
        SchemaMonitor::create('xe88_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊編號');
            $table->string('username', 20)->unique()->comment('xe88帳號');
            $table->decimal('balance', 18, 4)->default(0)->comment('xe88餘額');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('xe88_accounts');
    }
};
