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
        SchemaMonitor::create('checkin_prize_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('checkin_record_id')->index()->comment('打卡紀錄id');
            $table->unsignedInteger('checkin_prize_id')->comment('打卡獎品id');
            $table->timestamps();

            $table->foreign(['checkin_record_id'])->references(['id'])->on('checkin_records')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('checkin_prize_records');
    }
};
