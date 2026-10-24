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
        SchemaMonitor::create('domain_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('domain_id')->index()->comment('網域系統編號;');
            $table->integer('domain_type_info_id')->index()->comment('網域資訊系統編號;');
            $table->integer('agent_id')->index()->comment('代理系統編號;');
            $table->tinyInteger('status')->default(1)->index()->comment('0未使用,1使用中');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(1)->comment('更新者ID');

            $table->unique(['domain_id', 'agent_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('domain_agents');
    }
};
