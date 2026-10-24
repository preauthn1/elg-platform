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
        SchemaMonitor::create('transfer_limit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理群組編號');
            $table->decimal('amount', 18, 4)->comment('變更限額');
            $table->decimal('before_limit', 18, 4)->comment('變更前限額');
            $table->decimal('after_limit', 18, 4)->comment('變更後限額');
            $table->string('memo', 191)->nullable()->comment('備註');
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
        SchemaMonitor::dropIfExists('transfer_limit_logs');
    }
};
