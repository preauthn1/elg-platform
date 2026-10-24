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
        SchemaMonitor::create('ptsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商環境');
            $table->integer('member_id')->default(0)->index()->comment('我方會員ID');
            $table->string('username', 30)->unique()->comment('三方玩家帳號');
            $table->string('token')->nullable()->comment('外部令牌，用於標識玩家的遊戲週期。必須包含kiosk 前綴。');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ptsw_accounts');
    }
};
