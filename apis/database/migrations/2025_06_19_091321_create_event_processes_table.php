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
        SchemaMonitor::create('event_processes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('detail_id')->index()->comment('活動條件系統編號');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->decimal('point', 18, 4)->default(0)->comment('目前點數');
            $table->integer('received')->nullable()->default(0)->comment('已兌換過的金額');
            $table->dateTime('last_bet_time')->nullable();
            $table->boolean('status')->default(false)->comment('派獎狀態0:未達標1:已達標2:已派獎');
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
        SchemaMonitor::dropIfExists('event_processes');
    }
};
