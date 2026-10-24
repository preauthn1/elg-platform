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
        SchemaMonitor::create('agent_announcements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理id');
            $table->integer('announcement_id')->comment('公告id');
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_announcements');
    }
};
