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
        SchemaMonitor::create('brand_agents', function (Blueprint $table) {
            $table->comment('TGBOT所用的商家資訊');
            $table->increments('id');
            $table->integer('brand_id')->default(0)->index()->comment('brands.id');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->text('info')->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['brand_id', 'agent_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('brand_agents');
    }
};
