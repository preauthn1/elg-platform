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
        SchemaMonitor::create('marquee_texts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('代理id');
            $table->integer('exclude_agent_id')->comment('排除代理id');
            $table->tinyInteger('type')->comment('跑馬燈類別，1:一般 2:重要');
            $table->tinyInteger('status')->default(0);
            $table->dateTime('start_time')->index()->comment('起始時間');
            $table->dateTime('end_time')->index()->comment('結束時間');
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
        SchemaMonitor::dropIfExists('marquee_texts');
    }
};
