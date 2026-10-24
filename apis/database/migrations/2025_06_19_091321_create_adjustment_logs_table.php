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
        SchemaMonitor::create('adjustment_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('type')->default(0)->comment('adjustment_types.id');
            $table->integer('transaction_log_id')->default(0)->index()->comment('transaction_logs.id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->decimal('point', 18, 4)->default(0)->comment('分數');
            $table->string('remark', 191)->comment('備註');
            $table->string('operator', 191)->comment('操作者');
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
        SchemaMonitor::dropIfExists('adjustment_logs');
    }
};
