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
        SchemaMonitor::create('agent_medias', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理編號');
            $table->integer('media_id')->comment('影城編號');
            $table->tinyInteger('status')->default(0)->comment('狀態');
            $table->integer('backend_show')->comment('後台顯示');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_medias');
    }
};
