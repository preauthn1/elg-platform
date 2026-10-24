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
        SchemaMonitor::create('member_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('member_id');
            $table->unsignedTinyInteger('type')->comment('變更原因，見 MemberStatusLogTypeEnum');
            $table->unsignedTinyInteger('before_status');
            $table->unsignedTinyInteger('after_status');
            $table->unsignedBigInteger('operator')->default(0)->comment('後台操作者 admin_id；系統自動觸發為 0');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->index(['member_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['operator', 'created_at']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_status_logs');
    }
};
