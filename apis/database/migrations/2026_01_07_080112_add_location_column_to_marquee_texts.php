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
        SchemaMonitor::table('marquee_texts', function (Blueprint $table) {
            $table->integer('location')->after('exclude_agent_id')->default(1)->comment('跑馬燈位置;1:大廳、2:聊天室');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('marquee_texts', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
