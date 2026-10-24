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
        SchemaMonitor::create('announcement_messages_participants', function (Blueprint $table) {
            $table->comment('群發公告訊息哪些人可以看到');
            $table->increments('id');
            $table->integer('announcement_message_id')->comment('FK對應 announcement_message');
            $table->integer('type')->comment('類型 1: 針對chat_group  2. 針對代理樹狀  3. 針對單一代理  4. 針對特定Member  99. 針對組織 ');
            $table->integer('chat_group_id')->nullable()->comment('發送給特定的 chat_group');
            $table->integer('hierarchy_agent_id')->nullable()->comment('發送給特定的代理樹狀所有人');
            $table->integer('single_agent_id')->nullable()->comment('發送給特定的代理');
            $table->integer('member_id')->nullable()->comment('發送給特定的會員');
            $table->integer('organization_id')->nullable()->comment('發送給特定的組織');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('announcement_messages_participants');
    }
};
