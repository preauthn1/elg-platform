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
        SchemaMonitor::create('vip_rebate_proportions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vip_content_id')->comment('vip_contents.id');
            $table->integer('game_type_id')->comment('game_types.id');
            $table->decimal('proportion', 8, 4)->default(0)->comment('返水比例');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->default(0)->comment('管理員編號');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vip_rebate_proportions');
    }
};
