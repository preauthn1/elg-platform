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
        SchemaMonitor::create('event_refresh_rankings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->default(0)->comment('events.id');
            $table->integer('event_detail_id')->default(0)->comment('event_details.id');
            $table->integer('no')->default(0)->comment('期數');
            $table->dateTime('payout_time')->nullable()->comment('派發時間(UTC+0)');
            $table->integer('status')->default(0)->comment('狀態;0:未派發,1:已派發');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_refresh_rankings');
    }
};
