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
        SchemaMonitor::create('checkin_prizes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('checkin_activity_id')->index()->comment('打卡活動id');
            $table->integer('day')->comment('打卡天數');
            $table->integer('prize_type')->comment('獎品類型 1:平台點數 2:禮碼點數 3:商城點數');
            $table->decimal('prize_amount')->comment('獎品點數');
            $table->timestamps();

            $table->foreign(['checkin_activity_id'])->references(['id'])->on('checkin_activities')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('checkin_prizes');
    }
};
