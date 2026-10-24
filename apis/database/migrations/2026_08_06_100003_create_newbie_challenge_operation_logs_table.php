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
        SchemaMonitor::create('newbie_challenge_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('progress_id')->index()->comment('newbie_challenge_progress.id');
            $table->tinyInteger('operator_type')->comment('操作者類型; 1:system, 2:member, 3:agent');
            $table->unsignedInteger('operator_id')->nullable()->default(null)->comment('操作者ID，系統操作為null');
            $table->string('action')->comment('動作: 領取/充值核銷/流水達標/彩金撥款/放棄/逾期失效等');
            $table->tinyInteger('from_stage')->nullable()->default(null)->comment('狀態轉換前的stage');
            $table->tinyInteger('to_stage')->nullable()->default(null)->comment('狀態轉換後的stage');
            $table->string('remark')->nullable()->default(null)->comment('補充說明');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->index();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_operation_logs');
    }
};
