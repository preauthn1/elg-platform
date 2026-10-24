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
        SchemaMonitor::create('promotion_wallets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->index()->comment('events.id');
            $table->integer('parent')->comment('agents.id');
            $table->integer('member_id')->index()->comment('members.id');
            $table->decimal('balance', 18, 4)->comment('分數');
            $table->integer('unlock_type')->default(0)->comment('0:未解綁,1:達成條件,2:未達條件');
            $table->tinyInteger('status')->comment('狀態 0:解鎖,1:鎖定');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('promotion_wallets');
    }
};
