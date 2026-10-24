<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('member_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->integer('member_id')->comment('會員系統編號')->index();
            $table->string('type', 50)->comment('類型');
            $table->json('data')->nullable()->comment('資料');
            $table->timestamp('sent_at')->nullable()->comment('發送時間');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_tracking_events');
    }
};
