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
        SchemaMonitor::create('flow_limit_details', function (Blueprint $table) {
            $table->comment('稽核流水細表');
            $table->increments('id');
            $table->integer('flow_limit_id')->index()->comment('flow_limits.id');
            $table->bigInteger('start_total_bet_record_id')->comment('該筆稽核流水啟始ID');
            $table->bigInteger('end_total_bet_record_id')->comment('該筆稽核流水結束D');
            $table->decimal('start_flow_limit', 18, 4)->nullable()->default(0)->comment('稽核(起)');
            $table->decimal('end_flow_limit', 18, 4)->nullable()->default(0)->comment('稽核(迄)');
            $table->decimal('reach', 18, 4)->nullable()->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('flow_limit_details');
    }
};
