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
        SchemaMonitor::create('checkin_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('checkin_activity_id')->index()->comment('打卡活動id');
            $table->integer('member_id')->index()->comment('會員id');
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign(['checkin_activity_id'])->references(['id'])->on('checkin_activities')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('checkin_records');
    }
};
