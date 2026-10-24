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
        SchemaMonitor::create('mission_claim_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('mission_id')->index();
            $table->unsignedInteger('member_id')->index();
            $table->string('reward_type')->nullable()->default(null)->comment('獎勵類型 MissionRewardTypeEnum');
            $table->float('reward_value')->nullable()->default(null)->comment('獎勵值');
            $table->string('date')->nullable()->default(null)->comment('日期')->index();
            $table->string('week')->nullable()->default(null)->comment('年週')->index();
            $table->string('month')->nullable()->default(null)->comment('月份')->index();
            $table->dateTime('finish_time')->nullable()->default(null)->comment('完成時間')->index();
            $table->dateTime('claim_time')->nullable()->default(null)->comment('領取時間')->index();
            $table->boolean('is_claimed')->default(0)->comment('是否領取');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mission_claim_records');
    }
};
