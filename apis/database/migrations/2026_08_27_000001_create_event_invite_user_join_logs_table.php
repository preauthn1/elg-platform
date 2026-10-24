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
        SchemaMonitor::create('event_invite_user_join_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('before_status')->comment('修改前狀態，見 event_invite_user_joins.status');
            $table->integer('after_status')->comment('修改後狀態，見 event_invite_user_joins.status');
            $table->string('operator', 191)->comment('操作者');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_invite_user_join_logs');
    }
};
