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
        SchemaMonitor::create('tiktok_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('media_id')->comment('媒體ID');
            $table->string('username', 50)->comment('使用者名稱');
            $table->string('password', 50)->comment('密碼');
            $table->integer('member_id')->comment('會員ID');
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
        SchemaMonitor::dropIfExists('tiktok_accounts');
    }
};
