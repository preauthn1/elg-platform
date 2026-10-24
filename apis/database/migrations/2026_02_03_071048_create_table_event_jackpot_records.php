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
        SchemaMonitor::create('event_jackpot_records', function (Blueprint $table) {
            $table->id();
            $table->integer('member_id')->default(0)->comment('members.id');
            $table->integer('event_jackpot_id')->default(0)->comment('event_jackpots.id');
            $table->integer('prize_member_id')->default(0)->comment('prize_members.id');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:未通知,1:已通知');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

            $table->index('member_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_jackpot_records');
    }
};
