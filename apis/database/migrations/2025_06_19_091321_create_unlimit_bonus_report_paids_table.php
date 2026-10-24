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
        SchemaMonitor::create('unlimit_bonus_report_paids', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->index()->comment('玩家ID');
            $table->dateTime('summary_date')->index()->comment('統計時間');
            $table->decimal('performance_amount', 18, 4)->default(0)->comment('績效');
            $table->decimal('bonus_amount', 18, 4)->default(0)->comment('獎勵');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:未發放, 1:已發放');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['member_id', 'summary_date'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_bonus_report_paids');
    }
};
