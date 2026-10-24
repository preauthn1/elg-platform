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
        SchemaMonitor::create('checkin_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('總代理id');
            $table->unsignedInteger('flow_limit_multiple')->default(0)->comment('流水限制倍數');
            $table->unsignedTinyInteger('ip_block')->default(0)->comment('是否阻擋重複IP');
            $table->unsignedTinyInteger('phone_block')->default(0)->comment('是否阻擋重複手機號');
            $table->unsignedTinyInteger('account_block')->default(0)->comment('是否阻擋重複真實姓名');
            $table->timestamp('start_date')->nullable()->comment('開始時間');
            $table->timestamp('end_date')->nullable()->comment('結束時間');
            $table->unsignedTinyInteger('status')->default(1)->comment('狀態');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('checkin_activities');
    }
};
